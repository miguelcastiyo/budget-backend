<?php

declare(strict_types=1);

if (getenv('PRIVACY_PARITY_TEST') !== '1') {
    fwrite(STDOUT, "Skipping session reauthentication test: set PRIVACY_PARITY_TEST=1\n");
    exit(0);
}

$dsn = (string) getenv('DB_DSN');
if (!preg_match('/^mysql:.*dbname=([^;]+)/', $dsn, $match) || !str_ends_with($match[1], '_privacy_parity_test')) {
    fwrite(STDERR, "DB_DSN must point to the dedicated *_privacy_parity_test database\n");
    exit(2);
}

require __DIR__ . '/../src/bootstrap.php';

use App\Auth\AuthApplicationService;
use App\Auth\AuthService;
use App\Auth\GoogleTokenVerifier;
use App\Auth\AuthIdentityRepository;
use App\Auth\PasswordCredentialRepository;
use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Mail\Mailer;
use App\Security\AuditLogger;
use App\Support\Str;

$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$config = Config::load(dirname(__DIR__));
$suffix = bin2hex(random_bytes(6));
$email = "reauth-{$suffix}@example.test";
$password = 'reauth-test-password';
$deviceId = "dev-reauth-{$suffix}";
$sessionId = "ses-reauth-{$suffix}";
$sessionSecret = Str::randomHex(20);
$sessionToken = $sessionId . '.' . $sessionSecret;
$csrfToken = 'reauth-csrf-' . $suffix;
$userId = null;

function requestFor(string $token, string $csrfToken, array $payload): Request
{
    return new Request(
        'POST',
        '/auth/sessions/reauth',
        (string) json_encode($payload),
        [],
        ['sid' => $token],
        [],
        [],
        ['X-CSRF-Token' => $csrfToken, 'User-Agent' => 'reauth-contract-test']
    );
}

try {
    $insertUser = $pdo->prepare("INSERT INTO users (email, display_name, email_verified, role, is_active, financial_privacy_state) VALUES (:email, 'Reauth Test', 1, 'member', 1, 'encrypted')");
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insertUser->execute([':email' => $email]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO password_credentials (user_id, password_hash) VALUES (:user_id, :password_hash)')->execute([':user_id' => $userId, ':password_hash' => $hash]);
    $insertSession = $pdo->prepare("INSERT INTO user_sessions (session_id, user_id, device_id, session_secret_hash, csrf_token_hash, client_type, user_agent, created_at, expires_at) VALUES (:session_id, :user_id, :device_id, :secret_hash, :csrf_hash, :client_type, 'reauth-contract-test', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))");
    $insertSession->execute([
        ':session_id' => $sessionId,
        ':user_id' => $userId,
        ':device_id' => $deviceId,
        ':secret_hash' => Str::hashSha256($sessionSecret),
        ':csrf_hash' => Str::hashSha256($csrfToken),
        ':client_type' => 'web',
    ]);

    $application = new AuthApplicationService(
        $pdo,
        new AuthService($pdo, $config),
        new GoogleTokenVerifier($config),
        new Mailer($config),
        $config,
        new AuditLogger($pdo),
        new AuthIdentityRepository($pdo),
        new PasswordCredentialRepository($pdo)
    );

    try {
        $application->reauthenticateCurrentSession(requestFor($sessionToken, '', ['method' => 'password', 'password' => $password]));
        throw new RuntimeException('cookie reauthentication without CSRF was accepted');
    } catch (HttpException $e) {
        if ($e->status !== 403 || $e->errorCode !== 'FORBIDDEN') throw $e;
    }

    try {
        $application->reauthenticateCurrentSession(requestFor($sessionToken, $csrfToken, ['method' => 'password', 'password' => 'wrong-password']));
        throw new RuntimeException('wrong password was accepted');
    } catch (HttpException $e) {
        if ($e->status !== 401 || $e->errorCode !== 'UNAUTHENTICATED') throw $e;
    }

    $response = $application->reauthenticateCurrentSession(requestFor($sessionToken, $csrfToken, ['method' => 'password', 'password' => $password]));
    $body = json_decode($response->body, true);
    if ($response->status !== 200 || !is_array($body) || ($body['session']['session_id'] ?? '') === $sessionId) throw new RuntimeException('password reauthentication did not rotate the session');
    if (!isset($response->headers['Set-Cookie']) || !str_contains($response->headers['Set-Cookie'], 'sid=')) throw new RuntimeException('web reauthentication did not set a session cookie');

    $newSessionId = (string) $body['session']['session_id'];
    $old = $pdo->prepare('SELECT revoked_at FROM user_sessions WHERE session_id = :session_id');
    $old->execute([':session_id' => $sessionId]);
    if (($old->fetch()['revoked_at'] ?? null) === null) throw new RuntimeException('old session was not revoked');
    $new = $pdo->prepare('SELECT device_id, client_type, revoked_at FROM user_sessions WHERE session_id = :session_id');
    $new->execute([':session_id' => $newSessionId]);
    $newRow = $new->fetch();
    if (!is_array($newRow) || $newRow['device_id'] !== $deviceId || $newRow['client_type'] !== 'web' || $newRow['revoked_at'] !== null) throw new RuntimeException('replacement session did not preserve device/client state');

    try {
        $application->reauthenticateCurrentSession(requestFor($sessionToken, $csrfToken, ['method' => 'password', 'password' => $password]));
        throw new RuntimeException('revoked session was accepted');
    } catch (HttpException $e) {
        if ($e->status !== 401 || $e->errorCode !== 'UNAUTHENTICATED') throw $e;
    }

    $nativeSessionId = "ses-native-{$suffix}";
    $nativeSecret = Str::randomHex(20);
    $nativeToken = $nativeSessionId . '.' . $nativeSecret;
    $insertSession->execute([
        ':session_id' => $nativeSessionId,
        ':user_id' => $userId,
        ':device_id' => $deviceId,
        ':secret_hash' => Str::hashSha256($nativeSecret),
        ':csrf_hash' => Str::hashSha256('unused-native-csrf'),
        ':client_type' => 'native',
    ]);
    $nativeRequest = new Request(
        'POST',
        '/auth/sessions/reauth',
        (string) json_encode(['method' => 'password', 'password' => $password]),
        [],
        [],
        [],
        [],
        ['Authorization' => 'Session ' . $nativeToken, 'User-Agent' => 'reauth-native-contract-test']
    );
    $nativeResponse = $application->reauthenticateCurrentSession($nativeRequest);
    $nativeBody = json_decode($nativeResponse->body, true);
    if ($nativeResponse->status !== 200 || !is_string($nativeBody['session']['session_token'] ?? null)) throw new RuntimeException('native reauthentication did not return a session token');

    echo "Session reauthentication contract tests passed\n";
} finally {
    if ($userId !== null) {
        $pdo->prepare('DELETE FROM audit_logs WHERE actor_user_id = :user_id')->execute([':user_id' => $userId]);
        $pdo->prepare('DELETE FROM user_sessions WHERE user_id = :user_id')->execute([':user_id' => $userId]);
        $pdo->prepare('DELETE FROM password_credentials WHERE user_id = :user_id')->execute([':user_id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute([':user_id' => $userId]);
    }
}

<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Auth\AuthContext;
use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Security\AuditLogger;
use App\Support\Str;
use PDO;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\AuthenticationExtensions\AuthenticationExtensions;
use Webauthn\AuthenticationExtensions\PseudoRandomFunctionInputExtensionBuilder;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class QuickUnlockService
{
    private const PROFILE = 1;
    private const PURPOSE_REG = 'quick_unlock_registration';
    private const PURPOSE_ASSERT = 'quick_unlock_assertion';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly FinancialPrivacyStateService $states,
        private readonly VaultRepository $vaults,
        private readonly RecentAuthGuard $recentAuth,
        private readonly QuickUnlockRepository $repository,
        private readonly AuditLogger $audit
    ) {}

    public function registrationOptions(AuthContext $auth, Request $request): array
    {
        $this->interactive($auth); $this->states->requireEncryptedAuthority($auth->userId()); $this->vaultRequired($auth->userId()); $this->recentAuth->requireRecentInteractiveSession($auth);
        $input = $this->binary($request->input('prf_input'), 'prf_input', 32, 32);
        $challenge = random_bytes(32); $rpId = $this->rpId();
        $rp = PublicKeyCredentialRpEntity::create($this->config->get('WEBAUTHN_RP_NAME', 'Budget') ?? 'Budget', $rpId);
        $user = PublicKeyCredentialUserEntity::create('budget-user-'.$auth->userId(), hash('sha256', 'budget-quick-unlock-user:'.$auth->userId(), true), 'Budget user');
        $extension = AuthenticationExtensions::create([PseudoRandomFunctionInputExtensionBuilder::create()->withInputs($input)->build()]);
        $options = PublicKeyCredentialCreationOptions::create($rp, $user, $challenge, [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)], AuthenticatorSelectionCriteria::create(null, 'required'), PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE, [], 60000, $extension);
        return $this->saveOptions($auth, self::PURPOSE_REG, null, $challenge, $options, ['prf_input'=>$this->b64($input)]);
    }

    public function registrationComplete(AuthContext $auth, Request $request, array $payload): array
    {
        $this->interactive($auth); $this->states->requireEncryptedAuthority($auth->userId()); $this->vaultRequired($auth->userId()); $this->recentAuth->requireRecentInteractiveSession($auth);
        $challenge = $this->consume($auth, $payload, self::PURPOSE_REG); $options = $this->deserialize($challenge['options_json'], PublicKeyCredentialCreationOptions::class); $credential = $this->credential($payload);
        try { if (!$credential->response instanceof AuthenticatorAttestationResponse) throw new \RuntimeException('Invalid attestation response'); $factory=$this->ceremonyFactory(); $record=AuthenticatorAttestationResponseValidator::create($factory->creationCeremony())->check($credential->response,$options,$this->rpId()); }
        catch (Throwable) { throw new HttpException(422,'WEBAUTHN_VERIFICATION_FAILED','WebAuthn verification failed'); }
        $wrapped = isset($payload['wrapped_vault_key']) ? $this->binary($payload['wrapped_vault_key'],'wrapped_vault_key',40,512) : null;
        $status = $wrapped === null ? 'pending' : 'active'; $quickId=Str::randomId('quick');
        $recordJson=$this->serialize($record); $prfInput=$this->binary($payload['prf_input'] ?? null,'prf_input',32,32); $this->repository->insertCredential(['quick_unlock_id'=>$quickId,'user_id'=>$auth->userId(),'device_id'=>$auth->deviceId ?: $auth->sessionId,'credential_id'=>$credential->rawId,'credential_record'=>$recordJson,'signature_counter'=>$record->counter,'prf_input'=>$prfInput,'wrapped_vault_key'=>$wrapped ?? random_bytes(40),'status'=>$status]);
        return ['quick_unlock_id'=>$quickId,'status'=>$status,'needs_activation'=>$status==='pending','profile_version'=>self::PROFILE];
    }

    public function assertionOptions(AuthContext $auth, Request $request): array
    {
        $this->interactive($auth); $this->states->requireEncryptedAuthority($auth->userId()); $this->vaultRequired($auth->userId()); $rows=$this->repository->activeForDevice($auth->userId(),(string)($auth->deviceId ?: $auth->sessionId)); if ($rows===[]) throw new HttpException(404,'QUICK_UNLOCK_NOT_ENROLLED','Quick Unlock is not enrolled on this device');
        $challenge=random_bytes(32); $builder=PseudoRandomFunctionInputExtensionBuilder::create(); $allow=[]; foreach($rows as $row){$id=(string)$row['credential_id'];$allow[]=PublicKeyCredentialDescriptor::create('public-key',$id);$builder->withCredentialInputs($this->b64($id),(string)$row['prf_input']);}
        $options=PublicKeyCredentialRequestOptions::create($challenge,$this->rpId(),$allow,'required',60000,AuthenticationExtensions::create([$builder->build()]));
        return $this->saveOptions($auth,self::PURPOSE_ASSERT,null,$challenge,$options,[]);
    }

    public function status(AuthContext $auth): array
    {
        $this->interactive($auth);
        $this->states->requireEncryptedAuthority($auth->userId());
        $this->vaultRequired($auth->userId());
        $rows = $this->repository->activeForDevice($auth->userId(), (string) ($auth->deviceId ?: $auth->sessionId));
        $active = count(array_filter($rows, static fn (array $row): bool => (string) $row['status'] === 'active'));
        return ['status' => $active > 0 ? 'enrolled' : 'not_enrolled', 'quick_unlock_id' => $active > 0 ? (string) $rows[0]['quick_unlock_id'] : null, 'profile_version' => self::PROFILE];
    }

    public function assertionComplete(AuthContext $auth, Request $request, array $payload): array
    {
        $this->interactive($auth); $this->states->requireEncryptedAuthority($auth->userId()); $this->vaultRequired($auth->userId()); $challenge=$this->consume($auth,$payload,self::PURPOSE_ASSERT);$credential=$this->credential($payload);$row=$this->repository->findActiveByCredential($auth->userId(),(string)($auth->deviceId ?: $auth->sessionId),$credential->rawId);if($row===null)throw new HttpException(403,'QUICK_UNLOCK_DEVICE_MISMATCH','Quick Unlock is not authorized for this device');$options=$this->deserialize($challenge['options_json'],PublicKeyCredentialRequestOptions::class);
        try { if(!$credential->response instanceof AuthenticatorAssertionResponse)throw new \RuntimeException('Invalid assertion response');$record=$this->deserialize($row['credential_record'],\Webauthn\CredentialRecord::class);$updated=AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory()->requestCeremony())->check($record,$credential->response,$options,$this->rpId(),$credential->response->userHandle); } catch(Throwable){throw new HttpException(422,'WEBAUTHN_VERIFICATION_FAILED','WebAuthn verification failed');}
        $updatedJson=$this->serialize($updated); $wrapped=(string)$row['wrapped_vault_key'];
        if ((string)$row['status']==='pending') { $wrapped=$this->binary($payload['wrapped_vault_key']??null,'wrapped_vault_key',40,512); $this->repository->activate((string)$row['quick_unlock_id'],$wrapped,$updatedJson,$updated->counter); } else { $this->repository->updateAssertion((string)$row['quick_unlock_id'],$updatedJson,$updated->counter); }
        return ['quick_unlock_id'=>(string)$row['quick_unlock_id'],'profile_version'=>self::PROFILE,'status'=>'active','prf_input'=>$this->b64((string)$row['prf_input']),'wrapped_vault_key'=>$this->b64($wrapped)];
    }

    public function revoke(AuthContext $auth, Request $httpRequest, string $id): void { $this->interactive($auth);$this->recentAuth->requireRecentInteractiveSession($auth);if(!$this->repository->revoke($auth->userId(),$id))throw new HttpException(404,'QUICK_UNLOCK_NOT_FOUND','Quick Unlock credential not found');$this->audit->record($httpRequest,$auth,'quick_unlock.revoked','quick_unlock',$id,['profile_version'=>self::PROFILE]); }

    private function interactive(AuthContext $auth): void { if($auth->authType!=='session'||$auth->sessionId===null)throw new HttpException(403,'SESSION_REQUIRED','Interactive session required'); }
    private function vaultRequired(int $userId): void { if($this->vaults->findByUser($userId)===null)throw new HttpException(409,'QUICK_UNLOCK_VAULT_REQUIRED','Initialize the Vault before enrolling Quick Unlock'); }
    private function rpId(): string
    {
        $value = trim((string) ($this->config->get('WEBAUTHN_RP_ID') ?? ''));
        $production = strtolower(trim((string) ($this->config->get('APP_ENV', 'local') ?? 'local'))) === 'production';
        if ($value === '') {
            if ($production) throw new HttpException(500, 'WEBAUTHN_RP_ID_NOT_CONFIGURED', 'WebAuthn RP ID is not configured');
            return 'localhost';
        }
        if (str_contains($value, '://') || str_contains($value, '*') || str_contains($value, '/')) {
            throw new HttpException(500, 'WEBAUTHN_RP_ID_INVALID', 'WebAuthn RP ID is invalid');
        }
        return strtolower($value);
    }
    private function origins(): array
    {
        $value = trim((string) ($this->config->get('WEBAUTHN_ALLOWED_ORIGINS') ?? ''));
        $production = strtolower(trim((string) ($this->config->get('APP_ENV', 'local') ?? 'local'))) === 'production';
        if ($value === '') {
            if ($production) throw new HttpException(500, 'WEBAUTHN_ORIGIN_NOT_CONFIGURED', 'WebAuthn origin is not configured');
            return ['http://localhost:3000', 'http://127.0.0.1:3000'];
        }
        $origins = array_values(array_filter(array_map('trim', explode(',', $value))));
        if ($origins === [] || count($origins) !== count(array_unique($origins))) {
            throw new HttpException(500, 'WEBAUTHN_ORIGIN_INVALID', 'WebAuthn origin is invalid');
        }
        $rpId = $this->rpId();
        foreach ($origins as $origin) {
            $parts = parse_url($origin);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($host === '' || !in_array($scheme, ['http', 'https'], true) || str_contains($origin, '*') || isset($parts['path']) && $parts['path'] !== '' || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new HttpException(500, 'WEBAUTHN_ORIGIN_INVALID', 'WebAuthn origin is invalid');
            }
            if ($production && ($scheme !== 'https' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || !($host === $rpId || str_ends_with($host, '.' . $rpId)))) {
                throw new HttpException(500, 'WEBAUTHN_ORIGIN_INVALID', 'WebAuthn production origin is invalid');
            }
        }
        return $origins;
    }
    private function ceremonyFactory(): CeremonyStepManagerFactory { $f=new CeremonyStepManagerFactory();$f->setAllowedOrigins($this->origins());return$f; }
    private function serializer(): \Symfony\Component\Serializer\SerializerInterface { return (new WebauthnSerializerFactory(new AttestationStatementSupportManager()))->create(); }
    private function serialize(object $value): string { return $this->serializer()->serialize($value,'json',[\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES=>true]); }
    private function deserialize(string $json,string $class): object { return $this->serializer()->deserialize($json,$class,'json'); }
    private function credential(array $payload): PublicKeyCredential { if(!isset($payload['credential'])||!is_array($payload['credential']))throw new HttpException(422,'VALIDATION_ERROR','Credential payload is required');try{$c=$this->deserialize(json_encode($payload['credential'],JSON_THROW_ON_ERROR),PublicKeyCredential::class);if(!$c instanceof PublicKeyCredential)throw new \RuntimeException();return$c;}catch(Throwable){throw new HttpException(422,'VALIDATION_ERROR','Invalid credential payload');} }
    private function saveOptions(AuthContext $auth,string $purpose,?string $quickId,string $challenge,object $options,array $meta): array { $json=$this->serialize($options);$ttl=$this->config->getInt('WEBAUTHN_CHALLENGE_TTL_SECONDS',60);if($ttl<1||$ttl>300)throw new HttpException(500,'WEBAUTHN_CHALLENGE_TTL_INVALID','WebAuthn challenge TTL is invalid');$id=$this->repository->createChallenge($auth->userId(),(string)$auth->sessionId,$purpose,$quickId,$challenge,$json,gmdate('Y-m-d H:i:s',time()+$ttl));$out=json_decode($json,true,512,JSON_THROW_ON_ERROR);$out['challenge_id']=$id;return$out; }
    private function consume(AuthContext $auth,array $payload,string $purpose): array { $id=(int)($payload['challenge_id']??0);$r=$this->repository->consumeChallenge($id,$auth->userId(),(string)$auth->sessionId,$purpose);if($r===null)throw new HttpException(422,'WEBAUTHN_CHALLENGE_INVALID','WebAuthn challenge is invalid or expired');return$r; }
    private function binary(mixed $value,string $field,int $min,int $max): string { if(!is_string($value)||$value==='')throw new HttpException(422,'VALIDATION_ERROR',$field.' is required');$encoded=strtr($value,'-_','+/');$remainder=strlen($encoded)%4;if($remainder!==0)$encoded.=str_repeat('=',4-$remainder);$v=base64_decode($encoded,true);if($v===false||strlen($v)<$min||strlen($v)>$max)throw new HttpException(422,'VALIDATION_ERROR','Invalid '.$field);return$v; }
    private function b64(string $v): string { return rtrim(strtr(base64_encode($v),'+/','-_'),'='); }
}

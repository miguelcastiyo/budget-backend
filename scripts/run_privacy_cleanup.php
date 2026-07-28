<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
$config = App\Core\Config::load(dirname(__DIR__));
$pdo = App\Database\Connection::make($config);
$jobs = new App\Privacy\PrivacyCleanupRepository($pdo);
$service = new App\Privacy\PrivacyCleanupService($pdo, $jobs);
$jobsProcessed = [];
while (($job = $service->runNext()) !== null) {
    $jobsProcessed[] = ['cleanup_job_id' => $job['cleanup_job_id'], 'status' => $job['status']];
}
echo json_encode(['processed' => $jobsProcessed !== [], 'jobs' => $jobsProcessed], JSON_UNESCAPED_SLASHES) . PHP_EOL;

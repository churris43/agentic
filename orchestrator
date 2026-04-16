<?php
 
namespace App\Lambda;
 
use Aws\Sfn\SfnClient;
 
/**
 * Layer 1 — Orchestrator Lambda.
 * Receives the initial trigger, starts the Step Functions execution, and returns immediately.
 * Does no task work itself.
 */
class OrchestratorHandler
{
    private SfnClient $sfn;
    private string $stateMachineArn;
 
    public function __construct()
    {
        $this->sfn = new SfnClient([
            'region'  => env('AWS_DEFAULT_REGION', 'xx-xxx-xxxx'),
            'version' => 'latest',
        ]);
 
        $this->stateMachineArn = env('STATE_MACHINE_ARN');
    }
 
    /**
     * Lambda entry point.
     * $event contains: ['s3_path' => '...', 'db_id' => '...']
     */
    public function handle(array $event): array
    {
        $s3Path = $event['s3_path'];
        $dbId   = $event['db_id'];
 
        $response = $this->sfn->startExecution([
            'stateMachineArn' => $this->stateMachineArn,
            'input'           => json_encode([
                's3_path' => $s3Path,
                'db_id'   => $dbId,
            ]),
        ]);
 
        return [
            'statusCode'   => 200,
            'executionArn' => $response['executionArn'],
        ];
    }
}

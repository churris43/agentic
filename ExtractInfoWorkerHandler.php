<?php
/**
This code is to be treated as pseudocode and will have syntax mistakes.
It was written just to conceptualise an idea
*/
namespace App\Lambda;

use Aws\S3\S3Client;
use Anthropic\SDK\Client as AnthropicClient;

/**
 * Layer 3 — Worker Lambda for ExtractInfo.
 * Receives input from Step Functions, runs an internal agent loop,
 * and returns a structured result back to Step Functions.
 */
class ExtractInfoWorkerHandler
{
    private S3Client $s3;
    private AnthropicClient $llm;

    // This will be different as the LLM will be use through Bedrock
    private const MODEL = 'claude-sonnet-4-20250514';
    private const MAX_TOKENS = 1000;

    private const TASK_PROMPT = <<<PROMPT
        You are completing the ExtractInfo task in a document processing workflow.

        You have been given:
        - s3_path: the location of a PDF document in S3
        - labReportId: a record ID to associate the findings with

        Your job:
        1. Convert the PDF at the given s3_path to plain text using the convert_pdf_to_text tool
        2. Extract genes, variants and other information found in that text using the extract_information tool. 
        3. Store the information into the database using the store_information tool.

        When you are done, respond with a JSON object in this exact format:
        {"status": "complete", "summary": "<brief summary of what you found>", "labReportId": "<the labReportId>"}
        PROMPT;

    // ── Tool definitions passed to the LLM ──────────────────────────────────

    private const TOOLS = [
        [
            'name'         => 'convert_pdf_to_text',
            'description'  => 'Downloads a PDF from S3 and converts it to plain text. Returns the full text content of the document.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    's3_path' => [
                        'type'        => 'string',
                        'description' => 'Full S3 path to the PDF, e.g. s3://bucket/key.pdf',
                    ],
                ],
                'required'   => ['s3_path'],
            ],
        ],
        [
            'name'         => 'extract_information',
            'description'  => 'Scans plain text and extracts relevant information. Returns an array.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'text' => [
                        'type'        => 'string',
                        'description' => 'The plain text to scan for information.',
                    ],
                ],
                'required'   => ['text'],
            ],
        ],
        [
            'name'         => 'store_information',
            'description'  => 'Stores an array of extracted information into the findings table in the database.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'labReportId' => [
                        'type'        => 'string',
                        'description' => 'The record ID to associate the findings with.',
                    ],
                    'information' => [
                        'type'        => 'array',
                        'description' => 'Array of information to store.',
                        'items'       => [],
                    ],
                ],
                'required'   => ['labReportId', 'information'],
            ],
        ],
    ];

    public function __construct()
    {
        $this->s3 = new S3Client([
            'region'  => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'version' => 'latest',
        ]);

        $this->llm = new AnthropicClient(
            apiKey: env('ANTHROPIC_API_KEY'),
        );
    }

    // ── Lambda entry point ───────────────────────────────────────────────────

    /**
     * $event contains the output from the previous Step Functions state:
     * ['s3_path' => '...', 'labReportId' => '...']
     */
    public function handle(array $event): array
    {
        $s3Path = $event['s3_path'];
        $reportLabId   = $event['labReportId'];

        // This return value becomes the input to the next state in Step Functions
        return $this->runAgentLoop($s3Path, $reportLabId);
    }

    // ── Agent loop ───────────────────────────────────────────────────────────

    private function runAgentLoop(string $s3Path, string $reportLabId): array
    {
        $messages = [
            [
                'role'    => 'user',
                'content' => "Process this document. s3_path={$s3Path}, labReportId={$reportLabId}",
            ],
        ];
        $maxIterations = 10;
        $iteration = 0;
        
        while (true) {
            if (++$iteration > $maxIterations) {
                throw new AgentLoopException("Agent exceeded max iterations ({$maxIterations})");
            }
            $response = $this->llm->messages()->create([
                'model'      => self::MODEL,
                'max_tokens' => self::MAX_TOKENS,
                'system'     => self::TASK_PROMPT,
                'tools'      => self::TOOLS,
                'messages'   => $messages,
            ]);

            // Append assistant response to history
            $messages[] = [
                'role'    => 'assistant',
                'content' => $response->content,
            ];

            // If the LLM is done, extract and return the final result
            if ($response->stopReason === 'end_turn') {
                foreach ($response->content as $block) {
                    if ($block->type === 'text') {
                        return json_decode($block->text, associative: true);
                    }
                }
            }

            // Otherwise execute each tool call and feed results back
            $toolResults = [];
            foreach ($response->content as $block) {
                if ($block->type === 'tool_use') {
                    $result = $this->executeTool($block->name, (array) $block->input);
                    $toolResults[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $block->id,
                        'content'     => $result,
                    ];
                }
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }
    }

    // ── Tool dispatcher ──────────────────────────────────────────────────────

    private function executeTool(string $toolName, array $input): string
    {
        return match ($toolName) {
            'convert_pdf_to_text' => $this->convertPdfToText($input['s3_path']),
            'extract_information'       => $this->extractInformation($input['text']),
            'store_information'         => $this->storeInformation($input['labReportId'], $input['information']),
            default               => "Unknown tool: {$toolName}",
        };
    }

    // ── Tool implementations ─────────────────────────────────────────────────

    /**
     * Tool 1 — Download the PDF from S3 and convert it to plain text.
     * Uses smalot/pdfparser. Install via: composer require smalot/pdfparser
     */
    private function convertPdfToText(string $s3Path): string
    {
        $path = str_replace('s3://', '', $s3Path);
        [$bucket, $key] = explode('/', $path, 2);

        // Download the raw PDF bytes from S3
        $result  = $this->s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
        $pdfBytes = (string) $result['Body'];

        // Parse PDF bytes to plain text
        $parser  = new \Smalot\PdfParser\Parser();
        $pdf     = $parser->parseContent($pdfBytes);

        return $pdf->getText();
    }

    /**
     * Tool 2 — Scan plain text for anything that looks like variant, genes, etc ....
     * Returns an array with the information found
     */
    private function extractInformation(string $text): array
    {
        // Code to extract Information 
    }

    /**
     * Tool 3 — Store the extracted information in the findings table.
     * Assumes a Laravel DB connection is already configured.
     * Upserts based on labReportId so re-runs don't create duplicate rows.
     */
    private function storeInformation(int labReportId, array $information): string
    {
        // Code to loop through the extraced information and insert it into a table
        \Illuminate\Support\Facades\DB::table('findings')->insert(
            [
                'reportLabId' => $reportLabId,
                .....
            ],
        );

        return ;
    }
}

<?php

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

    private const MODEL = 'claude-sonnet-4-20250514';
    private const MAX_TOKENS = 1000;

    private const TASK_PROMPT = <<<PROMPT
        You are completing the ExtractInfo task in a document processing workflow.

        You have been given:
        - s3_path: the location of a PDF document in S3
        - db_id: a record ID to associate the findings with

        Your job:
        1. Convert the PDF at the given s3_path to plain text using the convert_pdf_to_text tool
        2. Extract all dates found in that text using the extract_dates tool. This is just an example to keep it simple
        3. Store the extracted dates into the database using the store_dates tool. This is just an example to keep it simple

        When you are done, respond with a JSON object in this exact format:
        {"status": "complete", "summary": "<brief summary of what you found>", "db_id": "<the db_id>"}
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
            'name'         => 'extract_dates',
            'description'  => 'Scans plain text and extracts anything that looks like a date. Returns a JSON object with a "dates" array.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'text' => [
                        'type'        => 'string',
                        'description' => 'The plain text to scan for dates.',
                    ],
                ],
                'required'   => ['text'],
            ],
        ],
        [
            'name'         => 'store_dates',
            'description'  => 'Stores an array of extracted dates into the findings table in the database.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'db_id' => [
                        'type'        => 'string',
                        'description' => 'The record ID to associate the findings with.',
                    ],
                    'dates' => [
                        'type'        => 'array',
                        'description' => 'Array of date strings to store.',
                        'items'       => ['type' => 'string'],
                    ],
                ],
                'required'   => ['db_id', 'dates'],
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
     * ['s3_path' => '...', 'db_id' => '...']
     */
    public function handle(array $event): array
    {
        $s3Path = $event['s3_path'];
        $dbId   = $event['db_id'];

        // This return value becomes the input to the next state in Step Functions
        return $this->runAgentLoop($s3Path, $dbId);
    }

    // ── Agent loop ───────────────────────────────────────────────────────────

    private function runAgentLoop(string $s3Path, string $dbId): array
    {
        $messages = [
            [
                'role'    => 'user',
                'content' => "Process this document. s3_path={$s3Path}, db_id={$dbId}",
            ],
        ];

        while (true) {
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
            'extract_dates'       => $this->extractDates($input['text']),
            'store_dates'         => $this->storeDates($input['db_id'], $input['dates']),
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
     * Tool 2 — Scan plain text for anything that looks like a date.
     * Returns a JSON object: {"dates": ["Jan 1 2024", "2024-06-15", ...]}
     */
    private function extractDates(string $text): string
    {
        $patterns = [
            // ISO: 2024-01-31
            '/\b\d{4}-\d{2}-\d{2}\b/',
            // AU/EU numeric: 31/01/2024 or 31-01-2024
            '/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}\b/',
            // US numeric: 01/31/2024
            '/\b\d{1,2}\/\d{1,2}\/\d{4}\b/',
            // Long form: January 31, 2024 or 31 January 2024
            '/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}\b/i',
            '/\b\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\b/i',
            // Short month: Jan 31 2024 or 31 Jan 2024
            '/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+\d{1,2},?\s+\d{4}\b/i',
            '/\b\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+\d{4}\b/i',
        ];

        $dates = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);
            $dates = array_merge($dates, $matches[0]);
        }

        // Deduplicate and reindex
        $dates = array_values(array_unique($dates));

        return json_encode(['dates' => $dates]);
    }

    /**
     * Tool 3 — Store the extracted dates in the findings table.
     * Assumes a Laravel DB connection is already configured.
     * Upserts based on db_id so re-runs don't create duplicate rows.
     */
    private function storeDates(string $dbId, array $dates): string
    {
        \Illuminate\Support\Facades\DB::table('findings')->insert(
            [
                'reportLabId' => $dbId,
                'dates'       => json_encode($dates),
                'updated_at'  => now(),
                'created_at'  => now(),
            ],
        );

        return "Stored " . count($dates) . " date(s) for id={$dbId}";
    }
}

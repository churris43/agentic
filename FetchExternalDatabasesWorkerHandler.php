<?php
/**
This code is to be treated as pseudocode and will have syntax mistakes.
It was written just to conceptualise an idea
*/
namespace App\Lambda;

use Anthropic\SDK\Client as AnthropicClient;

/**
 * Layer 3 — Worker Lambda for FetchExternalDatabases.
 * Receives a labReportId and a list of variants/genes from Step Functions,
 * queries ClinVar, ClinGen and OMIM for each item, and stores the findings.
 */
class FetchExternalDatabasesWorkerHandler
{
    private AnthropicClient $llm;

    private const MODEL      = 'claude-sonnet-4-20250514';
    private const MAX_TOKENS = 1000;

    private const TASK_PROMPT = <<<PROMPT
        You are completing the FetchExternalDatabases task in a document processing workflow.

        You have been given:
        - labReportId: the ID of the lab report these findings belong to
        - items: an array of items to look up. Each item has:
            - type: either "variant" or "gene"
            - name: the name or identifier of the variant or gene

        Your job:
        For each item in the items array:
        1. Query ClinVar using the fetch_clinvar tool to retrieve clinical significance data
        2. Query ClinGen using the fetch_clingen tool to retrieve gene-disease validity data
        3. Query OMIM using the fetch_omim tool to retrieve disease and phenotype data
        4. Once you have gathered results for ALL items from all three databases,
           call the store_information tool once to persist everything together.

        When you are done, respond with a JSON object in this exact format:
        {"status": "complete", "summary": "<brief summary of what was found and stored>", "labReportId": "<the labReportId>"}
        PROMPT;

    // ── Tool definitions passed to the LLM ──────────────────────────────────

    private const TOOLS = [
        [
            'name'        => 'fetch_clinvar',
            'description' => 'Queries the ClinVar database for clinical significance, condition associations, '
                           . 'and review status for a given variant or gene. Returns a JSON object with the findings.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'type' => [
                        'type'        => 'string',
                        'enum'        => ['variant', 'gene'],
                        'description' => 'Whether the lookup is for a variant or a gene.',
                    ],
                    'name' => [
                        'type'        => 'string',
                        'description' => 'The variant or gene identifier to look up, e.g. "BRCA1" or "NM_007294.3:c.5266dupC".',
                    ],
                ],
                'required' => ['type', 'name'],
            ],
        ],
        [
            'name'        => 'fetch_clingen',
            'description' => 'Queries the ClinGen database for gene-disease validity classifications, '
                           . 'dosage sensitivity, and actionability data for a given variant or gene. '
                           . 'Returns a JSON object with the findings.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'type' => [
                        'type'        => 'string',
                        'enum'        => ['variant', 'gene'],
                        'description' => 'Whether the lookup is for a variant or a gene.',
                    ],
                    'name' => [
                        'type'        => 'string',
                        'description' => 'The variant or gene identifier to look up.',
                    ],
                ],
                'required' => ['type', 'name'],
            ],
        ],
        [
            'name'        => 'fetch_omim',
            'description' => 'Queries the OMIM database for disease associations, inheritance patterns, '
                           . 'and phenotype descriptions for a given variant or gene. '
                           . 'Returns a JSON object with the findings.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'type' => [
                        'type'        => 'string',
                        'enum'        => ['variant', 'gene'],
                        'description' => 'Whether the lookup is for a variant or a gene.',
                    ],
                    'name' => [
                        'type'        => 'string',
                        'description' => 'The variant or gene identifier to look up.',
                    ],
                ],
                'required' => ['type', 'name'],
            ],
        ],
        [
            'name'        => 'store_information',
            'description' => 'Stores all findings gathered from ClinVar, ClinGen and OMIM into the database, '
                           . 'associated with the given lab report.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'labReportId' => [
                        'type'        => 'string',
                        'description' => 'The lab report ID to associate the findings with.',
                    ],
                    'findings' => [
                        'type'        => 'array',
                        'description' => 'Array of findings, one entry per item. Each entry contains the item name, type, and the results from each database.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'type'    => ['type' => 'string', 'description' => 'variant or gene'],
                                'name'    => ['type' => 'string', 'description' => 'The variant or gene name'],
                                'clinvar' => ['type' => 'object', 'description' => 'Raw findings from ClinVar'],
                                'clingen' => ['type' => 'object', 'description' => 'Raw findings from ClinGen'],
                                'omim'    => ['type' => 'object', 'description' => 'Raw findings from OMIM'],
                            ],
                        ],
                    ],
                ],
                'required' => ['labReportId', 'findings'],
            ],
        ],
    ];

    public function __construct()
    {
        $this->llm = new AnthropicClient(
            apiKey: env('ANTHROPIC_API_KEY'),
        );
    }

    // ── Lambda entry point ───────────────────────────────────────────────────

    /**
     * $event contains the output from the previous Step Functions state:
     * [
     *   'labReportId' => 'abc-123',
     *   'items'       => [
     *     ['type' => 'variant', 'name' => 'NM_007294.3:c.5266dupC'],
     *     ['type' => 'gene',    'name' => 'BRCA2'],
     *   ]
     * ]
     */
    public function handle(array $event): array
    {
        $labReportId = $event['labReportId'];
        $items       = $event['items'];

        // This return value becomes the input to the next state in Step Functions
        return $this->runAgentLoop($labReportId, $items);
    }

    // ── Agent loop ───────────────────────────────────────────────────────────

    private function runAgentLoop(string $labReportId, array $items): array
    {
        $itemsJson = json_encode($items);

        $messages = [
            [
                'role'    => 'user',
                'content' => "Fetch external database information for the following. labReportId={$labReportId}, items={$itemsJson}",
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
            'fetch_clinvar'      => $this->fetchClinVar($input['type'], $input['name']),
            'fetch_clingen'      => $this->fetchClinGen($input['type'], $input['name']),
            'fetch_omim'         => $this->fetchOmim($input['type'], $input['name']),
            'store_information'  => $this->storeInformation($input['labReportId'], $input['findings']),
            default              => "Unknown tool: {$toolName}",
        };
    }

    // ── Tool implementations ─────────────────────────────────────────────────

    /**
     * Tool 1 — Query the ClinVar API for a given variant or gene.
     * Returns a JSON string of the findings.
     */
    private function fetchClinVar(string $type, string $name): string
    {
        // Call the ClinVar API endpoint here using $type and $name.
        // Parse the response and return it as a JSON string.
    }

    /**
     * Tool 2 — Query the ClinGen API for a given variant or gene.
     * Returns a JSON string of the findings.
     */
    private function fetchClinGen(string $type, string $name): string
    {
        // Call the ClinGen API endpoint here using $type and $name.
        // Parse the response and return it as a JSON string.
    }

    /**
     * Tool 3 — Query the OMIM API for a given variant or gene.
     * Returns a JSON string of the findings.
     */
    private function fetchOmim(string $type, string $name): string
    {
        // Call the OMIM API endpoint here using $type and $name.
        // Parse the response and return it as a JSON string.
    }

    /**
     * Tool 4 — Store all gathered findings in the database associated with the lab report.
     * Assumes a Laravel DB connection is already configured.
     */
    private function storeInformation(string $labReportId, array $findings): string
    {
        // Insert a row into the findings table for each item in $findings.
        // Each row should store labReportId, the item type and name,
        // and the raw results from clinvar, clingen and omim as JSON fields.
    }
}

<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolResult;

/**
 * One allowlisted, read-only commerce tool the LLM may call mid-conversation.
 *
 * Matches the shape in references/architecture.md, with one addition:
 * description() — OpenAI's function-calling wire format requires a
 * description sibling to name/parameters (confirmed against
 * OpenAiProvider::buildTool() from Task 1), which the architecture.md
 * sketch didn't show but any real implementation needs.
 *
 * authorize() serves two purposes with one check: ToolCallingChatService
 * calls it once when deciding whether to offer the tool to the model at
 * all (a disabled capability means the tool is never in the request's
 * `tools` array in the first place), and again immediately before
 * execute() as defense in depth. It must throw ToolAuthorizationException
 * — never silently no-op — when the context isn't allowed to use this
 * tool (most commonly: its Assistant Capabilities toggle is off).
 *
 * execute() must never return anything sourced from the assistant search
 * index directly — every fact in the returned ToolResult must come from
 * live Magento data (LiveRevalidationServiceInterface or equivalent),
 * the same discipline the rest of the runtime pipeline already follows.
 */
interface CommerceToolInterface
{
    /**
     * Stable, unique tool name — must exactly match the key it is
     * registered under in etc/di.xml (see CommerceToolRegistryInterface).
     */
    public function name(): string;

    public function description(): string;

    /**
     * JSON-schema `parameters` object describing this tool's arguments,
     * exactly as OpenAiProvider::buildTool() expects it.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @throws ToolAuthorizationException when this context may not use this tool
     */
    public function authorize(ToolContext $context): void;

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(ToolContext $context, array $arguments): ToolResult;
}

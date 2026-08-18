<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatEntryPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatIdentityResolverInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatResponseSerializer;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Exception\ChatInputException;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestContentInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * POST /aichat/chat/send — the storefront chat endpoint. Accepts one raw
 * customer message, resolves real session-backed identity (conversation
 * id, customer group, cart — see ChatIdentityResolverInterface), and
 * returns ChatEntryPipelineInterface's result as JSON.
 *
 * Deliberately thin: every actual decision (is the assistant enabled, is
 * this message in scope, what identity applies) lives in
 * ChatIdentityResolverInterface/ChatEntryPipelineInterface, not here.
 * general.enabled is never re-checked here with different logic — an
 * unchanged ChatEntryPipeline::handle() call already returns the exact
 * same REASON_ASSISTANT_DISABLED SafeResponse it always has, which this
 * controller just serializes like any other outcome.
 *
 * CSRF: implements CsrfAwareActionInterface and accepts every request
 * (validateForCsrf() always true) rather than relying on Magento's
 * form-key mechanism, which assumes an HTML form submission — there is no
 * form here, only a same-origin JSON POST, the same pattern Magento's own
 * AJAX-only controllers use. This endpoint never itself mutates anything
 * directly (it only ever proxies to the confirmation-gated, capability-
 * gated pipeline built in Tasks 6-7), so the blast radius of skipping
 * form-key validation is low; genuine cross-customer protection here
 * comes from the session-cookie-scoped identity resolution below, not
 * from CSRF tokens.
 *
 * Not final: Magento generates a plugin interceptor for every controller
 * action class, which requires a non-final class — the same reason
 * Model\Config\Backend\InvalidateProductIndex (Task 4) is not final.
 */
class Send implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestContentInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ChatIdentityResolverInterface $identityResolver,
        private readonly ChatEntryPipelineInterface $chatEntryPipeline,
        private readonly ChatResponseSerializer $responseSerializer
    ) {
    }

    public function execute(): ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $rawMessage = $this->readMessage();

        try {
            $identity = $this->identityResolver->resolve($storeId);

            $result = $this->chatEntryPipeline->handle(
                $storeId,
                $rawMessage,
                $identity->customerGroupId,
                $identity->cartId,
                $identity->conversationId
            );
        } catch (ChatInputException $exception) {
            return $this->json(400, ['error' => 'invalid_input', 'message' => $exception->getMessage()]);
        } catch (StoreScopeException) {
            return $this->json(503, ['error' => 'store_unavailable']);
        }

        return $this->json(200, $this->responseSerializer->serialize($result));
    }

    private function readMessage(): string
    {
        $body = (string) $this->request->getContent();

        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && is_string($decoded['message'] ?? null)) {
                return $decoded['message'];
            }
        }

        $param = $this->request->getParam('message');

        return is_string($param) ? $param : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(int $httpStatusCode, array $data): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode($httpStatusCode);
        $result->setData($data);

        return $result;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}

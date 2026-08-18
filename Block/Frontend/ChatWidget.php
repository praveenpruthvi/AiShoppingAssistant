<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Block\Frontend;

use Aavirbhava\AiShoppingAssistant\Api\Config\AppearanceConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\AppearanceConfig;
use Aavirbhava\AiShoppingAssistant\Model\Config\ConfigurationReader;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * The persistent storefront chat widget (Task 11) — a pure view: it only
 * ever reads config to decide whether to render at all, and which
 * template to render (default/Luma vs. Hyva). Every actual chat decision
 * (identity, validation, retrieval, tool-calling, output validation) is
 * Controller\Chat\Send's job; this block and its templates only send a
 * message to that endpoint and render whatever JSON comes back — no
 * business logic is duplicated here.
 *
 * Hyva compatibility: Hyva deliberately ships no jQuery/Knockout/RequireJS
 * UI components stack, so a template built for Luma's stack will not
 * function on a Hyva storefront. Rather than requiring a merchant to
 * install a separate compatibility package, this block selects between
 * two templates itself at construction time by checking whether the
 * Hyva_Theme module is present — the same technique real third-party
 * Hyva-compatible extensions use (Magento\Framework\Module\Manager::
 * isEnabled() is safe to call with any module name, known or not; it is
 * a simple registry lookup, never an error, whether or not Hyva_Theme was
 * ever composer-required in this install). No Hyva theme is installed in
 * this dev environment (confirmed via composer.json/module:status before
 * writing this), so the Hyva path is built to this documented convention
 * but could not be rendered against a real Hyva theme — see the Task 11
 * status report for exactly what could and couldn't be live-verified.
 */
class ChatWidget extends Template
{
    private const HYVA_MODULE_NAME = 'Hyva_Theme';
    private const TEMPLATE_DEFAULT = 'Aavirbhava_AiShoppingAssistant::chat/widget.phtml';
    private const TEMPLATE_HYVA = 'Aavirbhava_AiShoppingAssistant::chat/widget-hyva.phtml';

    private ?AppearanceConfigInterface $appearance = null;

    public function __construct(
        Context $context,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly StoreManagerInterface $storeManager,
        private readonly ModuleManager $moduleManager,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->setTemplate($this->moduleManager->isEnabled(self::HYVA_MODULE_NAME) ? self::TEMPLATE_HYVA : self::TEMPLATE_DEFAULT);
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('aichat/chat/send');
    }

    public function getHistoryUrl(): string
    {
        return $this->getUrl('aichat/chat/history');
    }

    public function getPrimaryColor(): string
    {
        return $this->getAppearance()->primaryColor();
    }

    public function getPrimaryTextColor(): string
    {
        return $this->getAppearance()->primaryTextColor();
    }

    public function getMessageBubbleColor(): string
    {
        return $this->getAppearance()->messageBubbleColor();
    }

    public function getMessageTextColor(): string
    {
        return $this->getAppearance()->messageTextColor();
    }

    /**
     * Builds the inline `style` attribute value carrying every
     * `--aavirbhava-*` custom property the templates read. Always all
     * four, unconditionally: ConfigurationReader::readAppearance() never
     * returns null for any of them (explicit value, auto-computed
     * contrast pairing, or this module's original default — see its own
     * docblock), so there's no "was anything actually configured" branch
     * left here to make. Values are pre-validated to strict hex-color
     * syntax (or computed by this module itself) by ConfigurationReader,
     * so this never emits merchant-entered text as raw, unescaped CSS
     * beyond that already-validated literal.
     */
    public function getColorCustomPropertiesStyle(): string
    {
        $properties = [
            '--aavirbhava-primary-color' => $this->getPrimaryColor(),
            '--aavirbhava-primary-text-color' => $this->getPrimaryTextColor(),
            '--aavirbhava-message-bg-color' => $this->getMessageBubbleColor(),
            '--aavirbhava-message-text-color' => $this->getMessageTextColor(),
        ];

        $declarations = [];
        foreach ($properties as $name => $value) {
            $declarations[] = $name . ':' . $value;
        }

        return implode(';', $declarations);
    }

    /**
     * Same page-never-breaks discipline as isAssistantEnabled(): an
     * appearance-config read failure degrades to using this module's own
     * original hard-coded defaults directly (bypassing ColorContrast
     * entirely, since there's nothing configured left to pair against),
     * never a broken page. Cached per-request since both the individual
     * color getters and getColorCustomPropertiesStyle() read it, and a
     * template calls several of these per render.
     */
    private function getAppearance(): AppearanceConfigInterface
    {
        if ($this->appearance !== null) {
            return $this->appearance;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $this->appearance = $this->configurationReader->readAppearance($storeId);
        } catch (Throwable $exception) {
            $this->_logger->error('AI shopping assistant: chat widget appearance config read failed.', [
                'exception' => $exception->getMessage(),
            ]);

            $this->appearance = new AppearanceConfig(
                ConfigurationReader::DEFAULT_PRIMARY_COLOR,
                '#ffffff',
                ConfigurationReader::DEFAULT_MESSAGE_BUBBLE_COLOR,
                ConfigurationReader::DEFAULT_MESSAGE_TEXT_COLOR
            );
        }

        return $this->appearance;
    }

    /**
     * Never allowed to break page rendering for every storefront visitor:
     * a config read failure here degrades to "don't render the widget",
     * not a broken page. Every other consumer of ConfigurationReaderInterface
     * in this module (Controller\Chat\Send, ChatEntryPipeline, ...) lets a
     * ConfigurationException propagate deliberately, since a single chat
     * request failing is the correct, contained blast radius there — a
     * page-wide persistent block is a different risk profile.
     */
    protected function _toHtml(): string
    {
        if (!$this->isAssistantEnabled()) {
            return '';
        }

        return parent::_toHtml();
    }

    private function isAssistantEnabled(): bool
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();

            return $this->configurationReader->readGeneral($storeId)->isEnabled();
        } catch (Throwable $exception) {
            $this->_logger->error('AI shopping assistant: chat widget config read failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}

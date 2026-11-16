<?php
declare(strict_types=1);

namespace Tonyapps\ReviewReminder\Model;

use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Tonyapps\ReviewReminder\Model\ResourceModel\Invitation;

/**
 * Real-data preview for Tonyapps_EmailDesigner (registered in etc/di.xml, duck-typed): a shipped order's invitation,
 * built by InvitationSender::templateVars(). Read-only: no invitation row or token is created, nothing is marked.
 */
class EmailPreview
{
    /** Unsubscribe link of an order that has no invitation row yet; it shows "no longer valid". */
    public const PLACEHOLDER_TOKEN = 'preview';

    public function __construct(
        private readonly Invitation $invitation,
        private readonly InvitationSender $sender,
        private readonly Emulation $emulation
    ) {
    }

    /** @return array<string, string> */
    public function getRecords(string $templateId, int $limit): array
    {
        $records = [];
        foreach ($this->invitation->getPreviewOrders($limit) as $row) {
            $records[(string) $row['entity_id']] = implode(' · ', array_filter([
                '#' . $row['increment_id'],
                trim($row['customer_firstname'] . ' ' . $row['customer_lastname']) ?: (string) $row['customer_email'],
                substr((string) $row['created_at'], 0, 10),
            ]));
        }
        return $records;
    }

    /** The order's store, which the invitation goes out from; null for an unknown order. */
    public function getStoreId(string $templateId, string $recordId): ?int
    {
        $row = $this->invitation->getPreviewOrders(1, (int) $recordId)[0] ?? null;
        return $row ? (int) $row['store_id'] : null;
    }

    /** @return array<string, mixed> */
    public function getVariables(string $templateId, string $recordId): array
    {
        $row = $this->invitation->getPreviewOrders(1, (int) $recordId)[0] ?? null;
        if (!$row) {
            throw new LocalizedException(__('This order can not get a review invitation.'));
        }
        $storeId = (int) $row['store_id'];
        $token = (string) $row['token'] !== '' ? (string) $row['token'] : self::PLACEHOLDER_TOKEN;

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $vars = $this->sender->templateVars($row, $token, $storeId);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        if ($vars === null) {
            throw new LocalizedException(__('Nothing in this order can be reviewed, so no invitation would be sent.'));
        }
        return $vars;
    }
}

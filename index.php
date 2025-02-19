<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';


class LeadProcessor
{
    private const SOURCE = [
        "BAYUT_CALL" => "UC_V2L4X6",
        "BAYUT_EMAIL" => "UC_SP0A92",
        "BAYUT_WHATSAPP" => "UC_DPPLAR",
        "DUBIZZLE_CALL" => "UC_C75HXX",
        "DUBIZZLE_EMAIL" => "UC_67115U",
        "DUBIZZLE_WHATSAPP" => "UC_NUP3WI",
    ];

    private const PLATFORMS = ['bayut', 'dubizzle'];
    private const LEAD_TYPES = ['leads', 'call_logs', 'whatsapp_leads'];

    private $processedLeads;
    private $leadFile;
    private $authToken;
    private $timestamp;

    public function __construct($leadFile, $authToken, $timestamp)
    {
        $this->leadFile = $leadFile;
        $this->authToken = $authToken;
        $this->processedLeads = $this->getProcessedLeads();
        $this->timestamp = $timestamp;
    }

    public function processAllLeads()
    {
        $allLeads = $this->fetchAllLeads();

        foreach (self::PLATFORMS as $platform) {
            $this->processPlatformLeads($platform, $allLeads[$platform] ?? []);
        }
    }

    private function fetchAllLeads()
    {
        $allLeads = [];
        $encodedTimestamp = urlencode($this->timestamp);

        foreach (self::PLATFORMS as $platform) {
            foreach (self::LEAD_TYPES as $leadType) {
                $leads = $this->fetchLeads($leadType, $encodedTimestamp, $platform);
                $allLeads[$platform][$leadType] = is_array($leads) ? $leads : [];

                $this->logLeadCount($platform, $leadType, $allLeads[$platform][$leadType]);
            }
        }

        return $allLeads;
    }

    private function fetchLeads($leadType, $timestamp, $platform)
    {
        return fetchLeads($leadType, $timestamp, $this->authToken, $platform);
    }

    private function logLeadCount($platform, $leadType, $leads)
    {
        $formattedType = ucfirst(str_replace('_', ' ', $leadType));
        echo ucfirst($platform) . " {$formattedType}: " . count($leads) . "\n";
        logData("{$platform}_{$leadType}.log", json_encode($leads, JSON_PRETTY_PRINT));
    }

    private function processPlatformLeads($platform, $platformLeads)
    {
        foreach (self::LEAD_TYPES as $leadType) {
            if (!empty($platformLeads[$leadType])) {
                $formattedLeadType = str_replace('_', '', ucwords($leadType, '_'));
                $methodName = "process{$platform}{$formattedLeadType}";

                if (method_exists($this, $methodName)) {
                    $this->$methodName($platformLeads[$leadType]);
                } else {
                    logData('error.log', "Method not found: $methodName");
                    echo "Warning: Processing method for $methodName not implemented.\n";
                }
            }
        }
    }

    private function processBayutLeads($leads)
    {
        foreach ($leads as $lead) {
            if ($this->isProcessedLead($lead['lead_id'])) continue;

            $fields = [
                'TITLE' => "Bayut - Email - " . $lead['property_reference'] ?? 'No reference',
                'CATEGORY_ID' => SECONDARY_PIPELINE_ID,
                'ASSIGNED_BY_ID' => !empty($lead['property_reference']) ? getResponsiblePerson($lead['property_reference'], 'reference') : DEFAULT_ASSIGNED_USER_ID,
                'SOURCE_ID' => self::SOURCE['BAYUT_EMAIL'],
                'UF_CRM_1701770331658' => $lead['client_name'] ?? 'Unknown',
                'UF_CRM_65732038DAD70' => $lead['client_email'],
                'UF_CRM_PHONE_WORK' => $lead['client_phone'],
                'COMMENTS' => $lead['message'],
                'UF_CRM_6447D614AB1DF' => generatePropertyLink($lead['property_id']),
            ];

            $this->createLeadAndSave($fields, $lead['lead_id']);
        }
    }

    private function processBayutWhatsappLeads($leads)
    {
        foreach ($leads as $lead) {
            if ($this->isProcessedLead($lead['lead_id'])) continue;

            $fields = [
                'TITLE' => "Bayut - WhatsApp - " . $lead['listing_reference'] ?? $lead['detail']['actor_name'] ?? 'Unknown',
                'CATEGORY_ID' => SECONDARY_PIPELINE_ID,
                'ASSIGNED_BY_ID' => !empty($lead['listing_reference']) ? getResponsiblePerson($lead['listing_reference'], 'reference') : DEFAULT_ASSIGNED_USER_ID,
                'SOURCE_ID' => self::SOURCE['BAYUT_WHATSAPP'],
                'UF_CRM_1701770331658' => $lead['detail']['actor_name'] ?? 'Unknown',
                'UF_CRM_62A5B8743F62A' => $lead['detail']['cell'],
                'UF_CRM_6447D614AB1DF' => generatePropertyLink($lead['listing_id']),
                'COMMENTS' => $lead['detail']['message'],
            ];

            $this->createLeadAndSave($fields, $lead['lead_id']);
        }
    }

    private function processBayutCallLogs($leads)
    {
        foreach ($leads as $lead) {
            if ($this->isProcessedLead($lead['lead_id'])) continue;

            $fields = $this->prepareCallFields($lead, 'Bayut');
            $newLeadId = $this->createLeadAndSave($fields, $lead['lead_id']);

            if ($lead['call_recordingurl'] !== 'None') {
                $this->processCallRecording($lead, $fields, $newLeadId, 'Bayut');
            }
        }
    }

    private function processDubizzleLeads($leads)
    {
        foreach ($leads as $lead) {
            if ($this->isProcessedLead($lead['lead_id'])) continue;

            $fields = [
                'TITLE' => "Dubizzle - Email - " . $lead['property_reference'] ?? 'No reference',
                'CATEGORY_ID' => SECONDARY_PIPELINE_ID,
                'ASSIGNED_BY_ID' => !empty($lead['property_reference']) ? getResponsiblePerson($lead['property_reference'], 'reference') : DEFAULT_ASSIGNED_USER_ID,
                'SOURCE_ID' => self::SOURCE['DUBIZZLE_EMAIL'],
                'UF_CRM_1701770331658' => $lead['client_name'] ?? 'Unknown',
                'UF_CRM_65732038DAD70' => $lead['client_email'],
                'UF_CRM_PHONE_WORK' => $lead['client_phone'],
                'UF_CRM_6447D61518434' => $lead['property_reference'],
                'UF_CRM_660FC42E05A3E' => generatePropertyLink($lead['property_id']),
                'COMMENTS' => $lead['message'],
            ];

            $this->createLeadAndSave($fields, $lead['lead_id']);
        }
    }

    private function processDubizzleWhatsappLeads($leads)
    {
        foreach ($leads as $lead) {
            if ($this->isProcessedLead($lead['lead_id'])) continue;

            $messageData = parseMessageAndLink($lead['detail']['message']);
            $fields = [
                'TITLE' => "Dubizzle - WhatsApp - " . $lead['listing_reference'] ?? $lead['detail']['actor_name'] ?? 'Unknown',
                'CATEGORY_ID' => SECONDARY_PIPELINE_ID,
                'ASSIGNED_BY_ID' => !empty($lead['listing_reference']) ? getResponsiblePerson($lead['listing_reference'], 'reference') : DEFAULT_ASSIGNED_USER_ID,
                'SOURCE_ID' => self::SOURCE['DUBIZZLE_WHATSAPP'],
                'UF_CRM_1701770331658' => $lead['detail']['actor_name'] ?? 'Unknown',
                'UF_CRM_62A5B8743F62A' => $lead['detail']['cell'],
                'COMMENTS' => $messageData['message'],
                'UF_CRM_6447D61518434' => $lead['listing_reference'],
                'UF_CRM_660FC42E05A3E' => $messageData['link'],
            ];

            $this->createLeadAndSave($fields, $lead['lead_id']);
        }
    }

    private function processDubizzleCallLogs($leads)
    {
        foreach ($leads as $lead) {
            if ($this->isProcessedLead($lead['lead_id'])) continue;

            $fields = $this->prepareCallFields($lead, 'Dubizzle');
            $newLeadId = $this->createLeadAndSave($fields, $lead['lead_id']);

            if ($lead['call_recordingurl'] !== 'None' && $lead['call_recordingurl'] !== '') {
                $this->processCallRecording($lead, $fields, $newLeadId, 'Dubizzle');
            }
        }
    }

    private function prepareCallFields($lead, $platform)
    {
        $comments = $this->formatCallComments($lead);
        $SOURCE_ID = $platform === 'Bayut' ? self::SOURCE['BAYUT_CALL'] : self::SOURCE['DUBIZZLE_CALL'];

        $assignedById = DEFAULT_ASSIGNED_USER_ID;

        if (!empty($lead['listing_reference'])) {
            $assignedById = getResponsiblePerson($lead['listing_reference'], 'reference');
        } elseif (!empty($lead['receiver_number'])) {
            $responsiblePerson = getResponsiblePerson($lead['receiver_number'], 'phone');
            $assignedById = ($responsiblePerson === '1945') ? DEFAULT_ASSIGNED_USER_ID : $responsiblePerson;
        }

        return [
            'TITLE' => "{$platform} - Call - " . ($lead['listing_reference'] ? $lead['listing_reference'] : 'No reference'),
            'CATEGORY_ID' => SECONDARY_PIPELINE_ID,
            'ASSIGNED_BY_ID' =>  $assignedById,
            'SOURCE_ID' => $SOURCE_ID,
            'UF_CRM_1701770331658' => $lead['caller_number'] ?? 'Unknown',
            'UF_CRM_PHONE_WORK' => $lead['caller_number'],
            'COMMENTS' => $comments,
            'UF_CRM_6447D61518434' => $lead['listing_reference'],
        ];
    }


    private function formatCallComments($lead)
    {
        return "
            Receiver Number: {$lead['receiver_number']}
            Call Status: {$lead['call_status']}
            Call Duration: {$lead['call_total_duration']}
            Call Connected Duration: {$lead['call_connected_duration']}
            Call Recording URL: {$lead['call_recordingurl']}
        ";
    }

    private function processCallRecording($lead, $fields, $newLeadId, $platform)
    {
        $callRecordContent = @file_get_contents($lead['call_recordingurl']);
        if ($callRecordContent === false) {
            logData('error.log', "Failed to fetch call recording: {$lead['call_recordingurl']}");
            return;
        }

        $registerCall = $this->registerCall($lead, $fields, $newLeadId, $platform);
        $callId = $registerCall['CALL_ID'] ?? null;

        if ($callId) {
            $this->finishCallAndAttachRecord($callId, $fields, $lead, $callRecordContent);
        }
    }

    private function registerCall($lead, $fields, $newLeadId, $platform)
    {
        return registerCall([
            'USER_PHONE_INNER' => $lead['receiver_number'],
            'USER_ID' => $fields['ASSIGNED_BY_ID'],
            'PHONE_NUMBER' => $lead['caller_number'],
            'CALL_START_DATE' => $lead['date'] . ' ' . $lead['time'],
            'CRM_CREATE' => false,
            'CRM_SOURCE' => $fields['SOURCE_ID'],
            'CRM_ENTITY_TYPE' => 'DEAL',
            'CRM_ENTITY_ID' => $newLeadId,
            'SHOW' => false,
            'TYPE' => 2,
            'LINE_NUMBER' => $platform . ' ' . $lead['receiver_number'],
        ]);
    }

    private function finishCallAndAttachRecord($callId, $fields, $lead, $callRecordContent)
    {
        finishCall([
            'CALL_ID' => $callId,
            'USER_ID' => $fields['ASSIGNED_BY_ID'],
            'DURATION' => timeToSec($lead['call_connected_duration']),
            'STATUS_CODE' => 200,
        ]);

        attachRecord([
            'CALL_ID' => $callId,
            'FILENAME' => $lead['lead_id'] . '|' . uniqid('call') . '.mp3',
            'FILE_CONTENT' => base64_encode($callRecordContent),
        ]);
    }

    private function createLeadAndSave($fields, $leadId)
    {
        logData('fields.log', print_r($fields, true));

        $newLeadId = createBitrixDeal($fields);
        echo "New Lead Created: $newLeadId\n";

        $this->saveProcessedLead($leadId);
        return $newLeadId;
    }

    private function isProcessedLead($leadId)
    {
        if (in_array($leadId, $this->processedLeads)) {
            echo "Duplicate Lead Skipped: $leadId\n";
            return true;
        }
        return false;
    }

    private function getProcessedLeads()
    {
        return getProcessedLeads($this->leadFile);
    }

    private function saveProcessedLead($leadId)
    {
        saveProcessedLead($this->leadFile, $leadId);
        $this->processedLeads[] = $leadId;
    }
}

// Initialize and run the processor
try {
    $processor = new LeadProcessor(LEAD_FILE, AUTH_TOKEN, TIMESTAMP);
    $processor->processAllLeads();
} catch (Exception $e) {
    logData('error.log', "Error processing leads: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo "Error occurred while processing leads. Check error.log for details.\n";
}

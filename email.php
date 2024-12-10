<?php
namespace aw2\amazonses;

\aw2_library::add_service('amazonses.email_send', 'Send Amazon SES mail', ['namespace'=>__NAMESPACE__]);

function email_send($atts, $content = null, $shortcode) {
    //if (\aw2_library::pre_actions('all', $atts, $content, $shortcode) == false) return;
		
    extract(\aw2_library::shortcode_atts(array(
        'email' => null,
        'log' => null,
        'notification_object_type' => null,
        'notification_object_id' => null,
        'tracking_set' => null
    ), $atts, 'aw2_amazonses'));

    // If email is null, return
    if (is_null($email)) return;

   // Checking for values 
    if (!isset($email['from']['email_id']) || empty($email['from']['email_id'])) {
        return \aw2_library::post_actions('all', 'Sender email address is missing.', $atts);
    }
    if (!isset($email['to']['email_id']) || empty($email['to']['email_id'])) {
        return \aw2_library::post_actions('all', 'Recipient email address is missing.', $atts);
    }
    if (!isset($email['subject']) || empty($email['subject'])) {
        return \aw2_library::post_actions('all', 'Subject is missing.', $atts);
    }
    if (!isset($email['message']) || empty($email['message'])) {
        return \aw2_library::post_actions('all', 'Message is missing.', $atts);
    }
	
    // Get AWS credentials
    $awsKey = $email['vendor']['key'] ?? '';
    $awsSecret = $email['vendor']['secret'] ?? '';
    $awsRegion = $email['vendor']['region'] ?? 'ap-south-1';

    if (empty($awsKey) || empty($awsSecret)) {
        $return_value = \aw2_library::post_actions('all', 'AWS credentials are not provided, check your settings!', $atts);
        return $return_value;
    }

    try {
        $ses = new \Aws\Ses\SesClient([
            'version' => 'latest',
            'region'  => $awsRegion,
            'credentials' => [
                'key'    => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);

        $emailParams = [
            'Destination' => [
                'ToAddresses' => explode(',', $email['to']['email_id']),
            ],
            'Message' => [
                'Body' => [
                    'Html' => [
                        'Charset' => 'UTF-8',
                        'Data' => $email['message'],
                    ],
                ],
                'Subject' => [
                    'Charset' => 'UTF-8',
                    'Data' => $email['subject'],
                ],
            ],
            'Source' => $email['from']['email_id'],
        ];

        // Add CC if present
        if (isset($email['cc']['email_id'])) {
            $emailParams['Destination']['CcAddresses'] = explode(',', $email['cc']['email_id']);
        }

        // Add BCC if present
        if (isset($email['bcc']['email_id'])) {
            $emailParams['Destination']['BccAddresses'] = explode(',', $email['bcc']['email_id']);
        }

        // Add Reply-To if present
        if (isset($email['reply_to']['email_id']) && !empty($email['reply_to']['email_id'])) {
            $emailParams['ReplyToAddresses'] = explode(',', $email['reply_to']['email_id']);
        }
        
        // Handle attachments
        if (isset($email['attachments']['file'])) {
            // Convert to raw email for attachments
            $rawMessage = createRawEmailWithAttachments($email, $emailParams);
          
            $response = $ses->sendRawEmail([
                'RawMessage' => [
                    'Data' => $rawMessage,
                ],
            ]);
        } else {
            $response = $ses->sendEmail($emailParams);
        }
	if ($response['@metadata']['statusCode'] == 200){
            // Setting up tracking array
            $tracking['id'] = $response['MessageId'];
            $tracking['status'] = 'sent_to_provider';
            $tracking['stage'] = 'sent_to_provider';
            $ack['status'] = "success";
            $ack['tracking'] = $tracking;
            $ack['input'] = $email;
        }
        $return_value = $ack;

    } catch (\Aws\Exception\AwsException $e) {
        $return_value = "error: " . $e->getMessage();
    }
    return $return_value;
}


function createRawEmailWithAttachments($email, $emailParams) {
    $boundary = uniqid('boundary');
    $raw_message = '';

    // Headers
    $raw_message .= "From: {$emailParams['Source']}\r\n";
    $raw_message .= "To: " . implode(', ', $emailParams['Destination']['ToAddresses']) . "\r\n";
    if (isset($emailParams['Destination']['CcAddresses'])) {
        $raw_message .= "Cc: " . implode(', ', $emailParams['Destination']['CcAddresses']) . "\r\n";
    }
    if (isset($emailParams['Destination']['BccAddresses'])) {
        $raw_message .= "Bcc: " . implode(', ', $emailParams['Destination']['BccAddresses']) . "\r\n";
    }
    if (isset($emailParams['ReplyToAddresses'])) {
        $raw_message .= "Reply-To: " . implode(', ', $emailParams['ReplyToAddresses']) . "\r\n";
    }
    $raw_message .= "Subject: =?UTF-8?B?" . base64_encode($emailParams['Message']['Subject']['Data']) . "?=\r\n";
    $raw_message .= "MIME-Version: 1.0\r\n";
    $raw_message .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";

    // Message body
    $raw_message .= "--{$boundary}\r\n";
    $raw_message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $raw_message .= $emailParams['Message']['Body']['Html']['Data'] . "\r\n\r\n";

    // Attachments
    foreach ($email['attachments']['file'] as $attachment) {
        if (!empty($attachment['path'])) {
            $raw_message .= "--{$boundary}\r\n";
            $raw_message .= "Content-Type: application/octet-stream\r\n";
            $raw_message .= "Content-Transfer-Encoding: base64\r\n";
            $raw_message .= "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\r\n\r\n";
            $raw_message .= chunk_split(base64_encode(file_get_contents($attachment['path']))) . "\r\n";
        }
    }

    $raw_message .= "--{$boundary}--\r\n";

    return $raw_message;
}

<?php

/**
 * OPTIONAL — route SPIP transactional emails through Amazon SES.
 *
 * SPIP's native inc_envoyer_mail_dist() relies on PHP mail()/sendmail, which does not
 * work on Lambda/Bref (no local MTA). This override sends via the SES API (AWS SDK,
 * already bundled) using a verified identity.
 *
 * It is OPTIONAL and self-disabling: if SES_FROM (or SES_REGION) is not set it falls back
 * to SPIP's native mail(), so nothing changes unless you opt in. Enable it by setting
 * `ses_from_email` in your app stack tfvars (that also attaches the ses:SendEmail IAM
 * policy and injects SES_FROM/SES_REGION into the Lambda).
 *
 * Env (Lambda, set by Terraform when ses_from_email is provided):
 *   - SES_FROM   : e.g. "My Site <noreply@example.com>"  (default From; a verified identity)
 *   - SES_REGION : SES region for this account/env (defaults to AWS_REGION)
 *
 * The function signature mirrors inc_envoyer_mail_dist so SPIP calls it transparently.
 */

if (!defined('_ECRIRE_INC_VERSION')) {
    return;
}

function inc_envoyer_mail($destinataire, $sujet, $corps, $from = '', $headers = '') {
    $ses_from = getenv('SES_FROM') ?: '';
    $ses_region = getenv('SES_REGION') ?: (getenv('AWS_REGION') ?: '');

    // SES not configured → native behavior (opt-in feature stays off).
    if (!$ses_from || !$ses_region) {
        return sesmail_envoyer_natif($destinataire, $sujet, $corps, $from, $headers);
    }

    if (!sesmail_email_valide($destinataire)) {
        return false;
    }

    // SPIP passes either a plain-text string or an array with
    // 'texte', optional 'html', 'from', 'headers', 'cc', 'bcc'.
    $texte = '';
    $html = '';
    $cc = [];
    $bcc = [];
    if (is_array($corps)) {
        $texte = $corps['texte'] ?? '';
        $html = $corps['html'] ?? '';
        $from = $corps['from'] ?? $from;
        if (!empty($corps['cc'])) {
            $cc = is_array($corps['cc']) ? $corps['cc'] : [$corps['cc']];
        }
        if (!empty($corps['bcc'])) {
            $bcc = is_array($corps['bcc']) ? $corps['bcc'] : [$corps['bcc']];
        }
    } else {
        $texte = (string) $corps;
    }

    // SES requires the From to be (or belong to) the verified identity, so we keep
    // SES_FROM as the envelope From and expose a requested address as Reply-To.
    $source = $ses_from;
    $reply_to = [];
    if ($from && stripos($from, '@') !== false) {
        $reply_to[] = $from;
    }

    $charset = $GLOBALS['meta']['charset'] ?? 'utf-8';

    $body = [];
    if ($texte !== '') {
        $body['Text'] = ['Data' => $texte, 'Charset' => $charset];
    }
    if ($html !== '') {
        $body['Html'] = ['Data' => $html, 'Charset' => $charset];
    }
    if (!$body) {
        return false;
    }

    try {
        require_once '/var/task/vendor/autoload.php';
        $client = new \Aws\Ses\SesClient([
            'version' => 'latest',
            'region'  => $ses_region,
        ]);

        $destination = ['ToAddresses' => [$destinataire]];
        if ($cc) {
            $destination['CcAddresses'] = $cc;
        }
        if ($bcc) {
            $destination['BccAddresses'] = $bcc;
        }

        $args = [
            'Source'      => $source,
            'Destination' => $destination,
            'Message'     => [
                'Subject' => ['Data' => $sujet, 'Charset' => $charset],
                'Body'    => $body,
            ],
        ];
        if ($reply_to) {
            $args['ReplyToAddresses'] = $reply_to;
        }

        $res = $client->sendEmail($args);
        spip_log('SES sendEmail OK to ' . $destinataire . ' — MessageId=' . ($res['MessageId'] ?? '?'), 'ses');
        return true;
    } catch (\Throwable $e) {
        spip_log('SES sendEmail FAILED to ' . $destinataire . ' : ' . $e->getMessage(), 'ses' . _LOG_ERREUR);
        return false;
    }
}

/**
 * Fallback via PHP mail() when SES is not configured (local dev with an MTA).
 */
function sesmail_envoyer_natif($destinataire, $sujet, $corps, $from = '', $headers = '') {
    if (!sesmail_email_valide($destinataire)) {
        return false;
    }
    $texte = is_array($corps) ? ($corps['texte'] ?? '') : (string) $corps;
    $hdr = '';
    if ($from) {
        $hdr .= 'From: ' . $from . "\r\n";
    }
    if ($headers) {
        $hdr .= is_array($headers) ? implode("\r\n", $headers) : $headers;
    }
    return @mail($destinataire, $sujet, $texte, $hdr);
}

/**
 * Email validation that doesn't depend on SPIP's inc/filtres being loaded here.
 */
function sesmail_email_valide($email) {
    if (function_exists('email_valide')) {
        return email_valide($email);
    }
    return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL);
}

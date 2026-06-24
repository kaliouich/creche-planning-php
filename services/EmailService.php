<?php
/**
 * Template HTML pour l'email de notification du planning publié.
 * 
 * @param string $firstName Prénom du destinataire
 * @param int    $weekNumber Numéro de semaine
 * @param string $tableHtml Tableau HTML du planning
 * @param string $appUrl URL de l'application
 * @return string HTML complet de l'email
 */
function render_published_email(string $firstName, int $weekNumber, string $tableHtml, string $appUrl): string {
    $firstName = htmlspecialchars($firstName);
    return <<<HTML
Bonjour {$firstName},<br><br>
Le planning de la semaine <strong>{$weekNumber}</strong> vient d'être publié.<br><br>
{$tableHtml}
<br><br>
Vous pouvez vous connecter pour plus de détails : <a href="{$appUrl}">{$appUrl}</a><br><br>
Au moindre besoin, contactez-nous sur l'adresse email du planning.<br><br>
Le Pôle Planning.
HTML;
}

/**
 * Template HTML pour l'email d'ouverture des disponibilités.
 * 
 * @param string $firstName Prénom du destinataire
 * @param int    $weekNumber Numéro de semaine
 * @param string $appUrl URL de l'application
 * @return string HTML complet de l'email
 */
function render_open_email(string $firstName, int $weekNumber, string $appUrl): string {
    $firstName = htmlspecialchars($firstName);
    return <<<HTML
Bonjour {$firstName},<br><br>
La semaine <strong>{$weekNumber}</strong> est désormais ouverte pour la saisie de vos disponibilités.<br><br>
Merci de vous rendre sur l'application pour indiquer vos choix : <a href="{$appUrl}">{$appUrl}</a><br><br>
Au moindre besoin, contactez-nous sur l'adresse email du planning.<br><br>
Le Pôle Planning.
HTML;
}

/**
 * Envoie un email avec gestion d'erreurs loggées (au lieu de @mail silencieux).
 * 
 * @param string $to Adresse email du destinataire
 * @param string $subject Sujet de l'email
 * @param string $message Corps HTML
 * @param array  $headers En-têtes email
 * @return bool Succès de l'envoi
 */
function send_email(string $to, string $subject, string $message, array $headers = []): bool {
    if (empty($headers)) {
        $headers = [
            'From' => 'planning@lesfruitsdelapassion.fr',
            'Reply-To' => 'planning@lesfruitsdelapassion.fr',
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Mailer' => 'PHP/' . phpversion()
        ];
    }

    $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $success = mail($to, $encodedSubject, $message, $headers);

    if (!$success) {
        Logger::warning("Email non envoyé", [
            'to' => $to,
            'subject' => $subject,
            'error' => error_get_last()['message'] ?? 'unknown',
        ]);
    }

    return $success;
}

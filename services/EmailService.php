<?php
require_once __DIR__ . '/Logger.php';

/**
 * Template HTML pour l'email de notification du planning publié.
 * 
 * @param string $firstName Prénom du destinataire
 * @param int    $weekNumber Numéro de semaine
 * @param string $tableHtml Tableau HTML du planning
 * @param string $appUrl URL de l'application
 * @param bool   $isForced Vrai si le parent a été assigné malgré son indisponibilité
 * @return string HTML complet de l'email
 */
function render_published_email(string $firstName, int $weekNumber, string $tableHtml, string $appUrl, bool $isForced = false, string $exchangeMessage = ''): string {
    $firstName = htmlspecialchars($firstName);
    $warning = '';
    if ($isForced) {
        $warning = '<div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 1rem; margin-bottom: 1.5rem; color: #991b1b;">
            <strong style="display: block; margin-bottom: 0.5rem;">⚠️ Alerte : Assignation exceptionnelle</strong>
            En raison d\'un manque d\'effectif pour cette semaine, l\'administration a dû vous assigner exceptionnellement à une permanence malgré votre indisponibilité.
        </div>';
    }
    $exchangeDiv = '';
    if ($exchangeMessage !== '') {
        $exchangeDiv = '<div style="background-color: #f0fdf4; border-left: 4px solid #22c55e; padding: 1rem; margin-bottom: 1.5rem; color: #166534;">
            <strong style="display: block; margin-bottom: 0.5rem;">✅ Échange de permanence validé</strong>
            ' . htmlspecialchars($exchangeMessage) . '
        </div>';
    }
    return <<<HTML
Bonjour {$firstName},<br><br>
{$warning}
{$exchangeDiv}
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
 * Template HTML pour l'email de bienvenue (création de compte automatique).
 */
function render_welcome_email(string $appUrl, string $token): string {
    $link = $appUrl . '/reset-password?token=' . $token;
    return <<<HTML
Bonjour et bienvenue ! 🎉<br><br>
Nous avons le plaisir de vous annoncer la création de votre compte parent sur la plateforme <strong>Crèche Planning - Les Fruits de la Passion</strong>.<br><br>
Ce portail vous sera indispensable pour :<br>
<ul>
    <li>Saisir vos <strong>disponibilités</strong> pour les permanences à la crèche.</li>
    <li>Consulter en temps réel les <strong>plannings hebdomadaires</strong> publiés.</li>
    <li>Suivre l'évolution de votre <strong>solde de points</strong> (statut "En Perm" ou "En Relâche").</li>
    <li>Déclarer des <strong>absences</strong> ou des congés.</li>
</ul><br>
Pour accéder à votre espace, il ne vous reste plus qu'à choisir un mot de passe sécurisé. Cliquez simplement sur le lien ci-dessous pour le définir :<br><br>
👉 <a href="{$link}"><strong>Créer mon mot de passe et me connecter</strong></a><br><br>
<i>(Ce lien est sécurisé et restera valide pendant 24 heures)</i><br><br>
Nous vous remercions pour votre engagement à nos côtés et avons hâte de vous retrouver à la crèche !<br><br>
L'équipe du Pôle Planning.
HTML;
}

/**
 * Template HTML pour l'email de bienvenue d'un Administrateur.
 */
function render_admin_welcome_email(string $appUrl, string $token): string {
    $link = $appUrl . '/reset-password?token=' . $token;
    return <<<HTML
Bonjour et bienvenue dans l'équipe d'administration ! 🛡️<br><br>
Nous avons le plaisir de vous annoncer la création de votre compte <strong>Administrateur</strong> sur la plateforme <strong>Crèche Planning - Les Fruits de la Passion</strong>.<br><br>
En tant qu'Admin du Pôle Planning, ce portail vous permet de gérer le fonctionnement de la crèche :<br>
<ul>
    <li><strong>Gestion des semaines :</strong> Création et ouverture des semaines de planning.</li>
    <li><strong>Paramétrage du calendrier :</strong> Spécifier les jours de fermeture, les besoins en double permanence, etc.</li>
    <li><strong>Gestion des familles :</strong> Ajouter ou supprimer des enfants et créer les comptes parents.</li>
    <li><strong>Calculs et Publication :</strong> Calculer automatiquement les soldes de permanences et publier le planning final.</li>
</ul><br>
Pour activer vos droits d'administrateur, il ne vous reste plus qu'à choisir un mot de passe sécurisé. Cliquez simplement sur le lien ci-dessous pour le définir :<br><br>
👉 <a href="{$link}"><strong>Créer mon mot de passe et me connecter</strong></a><br><br>
<i>(Ce lien est sécurisé et restera valide pendant 24 heures)</i><br><br>
Merci pour votre implication dans la gestion de la crèche !<br><br>
L'équipe du Pôle Planning.
HTML;
}

/**
 * Template HTML pour la réinitialisation de mot de passe.
 */
function render_reset_password_email(string $appUrl, string $token): string {
    $link = $appUrl . '/reset-password?token=' . $token;
    return <<<HTML
Bonjour,<br><br>
Vous avez demandé à réinitialiser votre mot de passe.<br><br>
Cliquez sur le lien ci-dessous pour choisir un nouveau mot de passe :<br>
<a href="{$link}">Réinitialiser mon mot de passe</a><br><br>
Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet email.<br>
Ce lien est valable 24 heures.<br><br>
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

/**
 * Template HTML pour l'email de passage en Relâche
 */
function render_relache_email(string $firstName, float $newScore, string $appUrl): string {
    $firstName = htmlspecialchars($firstName);
    $scoreStr = number_format($newScore, 2);
    return <<<HTML
Bonjour {$firstName},<br><br>
Bonne nouvelle ! Suite à la publication de la dernière semaine, votre solde de permanence est remonté en positif (<strong>+{$scoreStr}</strong>).<br><br>
Vous passez officiellement au statut <strong>"En Relâche"</strong> ☕.<br>
Grâce à votre disponibilité et votre mobilisation, vous êtes récompensé(e) par une semaine de relâche. Vous pouvez choisir entre profiter de votre relâche, ou bien refaire une permanence pour augmenter encore votre score et cumuler d'autres relâches futures !<br><br>
Vous pouvez vous connecter pour voir les détails : <a href="{$appUrl}">{$appUrl}</a><br><br>
Le Pôle Planning.
HTML;
}

/**
 * Template HTML pour l'email de passage en Permanence
 */
function render_perm_email(string $firstName, float $newScore, string $appUrl): string {
    $firstName = htmlspecialchars($firstName);
    $scoreStr = number_format($newScore, 2);
    return <<<HTML
Bonjour {$firstName},<br><br>
Alerte Permanence ⚠️<br><br>
Suite à la consommation des derniers jours, votre solde de permanence est passé en négatif (<strong>{$scoreStr}</strong>).<br>
Vous quittez donc le statut "Relâche" et repassez en statut <strong>"En Perm"</strong> 🟩.<br><br>
Vous êtes donc convié(e) à planifier une disponibilité pour les prochaines semaines après cette période de relâche. Merci encore pour votre précieuse contribution !<br><br>
Connectez-vous pour remplir vos disponibilités : <a href="{$appUrl}">{$appUrl}</a><br><br>
Le Pôle Planning.
HTML;
}

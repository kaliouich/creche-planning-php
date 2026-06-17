<?php
/**
 * Configuration de l'application Crèche Planning.
 * 
 * En production (OVH), modifiez les valeurs ci-dessous avec les identifiants
 * fournis par votre espace client OVH (Web Cloud → Hébergements → Bases de données).
 */

// ─── Base de données MySQL ───────────────────────────────────
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'creche_planning');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// ─── JWT ─────────────────────────────────────────────────────
// IMPORTANT : Changez ce secret en production !
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'CHANGE_ME_IN_PRODUCTION_use_a_long_random_string_here');
define('JWT_EXPIRATION', 8 * 3600); // 8 heures en secondes

// ─── CORS ────────────────────────────────────────────────────
// En production, mettre l'URL de votre frontend (ex: https://creche.votredomaine.fr)
define('CORS_ORIGINS', getenv('CORS_ORIGINS') ?: 'http://localhost:5173,http://127.0.0.1:5173');

// ─── Environnement ──────────────────────────────────────────
define('IS_PRODUCTION', getenv('APP_ENV') === 'production');

// ─── Rate Limiting (simple) ─────────────────────────────────
define('AUTH_RATE_LIMIT_MAX', 10);        // Max tentatives de login
define('AUTH_RATE_LIMIT_WINDOW', 900);    // Par fenêtre de 15 min (en secondes)

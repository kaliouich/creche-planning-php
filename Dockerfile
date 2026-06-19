FROM php:8.2-apache

# Installer l'extension pdo_mysql pour se connecter à MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Activer le mod_rewrite d'Apache pour que notre routeur (index.php) fonctionne via le .htaccess
RUN a2enmod rewrite

WORKDIR /var/www/html

# Imagen oficial PHP + Apache
FROM php:8.0-apache

# Instala extensiones mínimas y habilita módulos
RUN docker-php-ext-install pdo pdo_mysql \
 && a2enmod rewrite headers

# Establece timezone (opcional)
ENV TZ=America/Guatemala
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# DocumentRoot -> /var/www/html/public (tu frontend + index.php)
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#' /etc/apache2/sites-available/000-default.conf

# Permitir .htaccess en /public
RUN printf "<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n" > /etc/apache2/conf-available/zkt.conf \
 && a2enconf zkt

# Copiamos TODO tu backend (incluye /public) al docroot
# Estructura esperada: backend/ (con src, config, public, etc.)
COPY backend/ /var/www/html/

# Script de arranque: crea .env y arranca Apache
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080
# Apache escucha en 80 dentro del contenedor, App Platform lo expone en 8080
ENV APACHE_RUN_USER www-data
ENV APACHE_RUN_GROUP www-data

CMD ["/usr/local/bin/start.sh"]

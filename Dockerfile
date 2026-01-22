FROM php:8.2-apache

RUN apt-get update && apt-get install -y libxml2-dev \
  && docker-php-ext-install soap

RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN a2enmod rewrite

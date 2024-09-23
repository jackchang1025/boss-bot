FROM php:8.2-cli

# 设置工作目录
WORKDIR /var/www/html

# 安装依赖
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 复制项目文件
COPY . .

# 安装 PHP 依赖
RUN composer install

# 设置入口点（可选）
# 设置容器不退出
CMD ["tail", "-f", "/dev/null"]
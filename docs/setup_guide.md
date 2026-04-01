# Руководство пользователя и настройка проекта

## 1. Предварительные требования

Чтобы запустить приложение, пользователю нужен локальный веб‑сервер с поддержкой PHP.  
Рекомендуемая связка:

- XAMPP для Windows (Apache + PHP)
- Браузер (Chrome, Firefox, Edge и т.п.)

База данных (MySQL/MariaDB) для этого проекта не требуется.

### 1.1. Установка XAMPP

1. Скачать установщик XAMPP с официального сайта Apache Friends.
2. Установить XAMPP по умолчанию (обычно `C:/xampp`).
3. Запустить **XAMPP Control Panel**.
4. В панели управления запустить модуль **Apache** (кнопка *Start*).

После запуска Apache по адресу `http://localhost/` должна открываться стартовая страница XAMPP.

## 2. Структура папок проекта

Проект разворачивается в подпапке `site1/Route` каталога `htdocs`:

```text
C:/xampp/htdocs/
└── site1/
    └── Route/
        ├── index.php
        ├── route_logic.php
        ├── route.css
        └── docs/
            ├── README.md
            ├── architecture.md
            ├── neural_net.md
            ├── business_analysis.md
            └── setup_guide.md
```

## 3. Развёртывание проекта (без виртуальных хостов)

1. Создать папку `C:/xampp/htdocs/site1/Route`.
2. Скопировать в неё файлы `index.php`, `route_logic.php`, `route.css` и папку `docs/`.
3. Убедиться, что Apache запущен.
4. Открыть в браузере URL:
   - `http://localhost/site1/Route/index.php`

## 4. Настройка виртуального хоста в Apache

Для более «красивого» адреса можно настроить виртуальный хост, например `site1.local`.

### 4.1. Настройка файла hosts в Windows

1. Открыть блокнотом от имени администратора файл:
   - `C:/Windows/System32/drivers/etc/hosts`
2. Добавить в конец строку:

```text
127.0.0.1   site1.local
```

3. Сохранить файл.

### 4.2. Настройка виртуального хоста в Apache (XAMPP)

1. Открыть файл `C:/xampp/apache/conf/extra/httpd-vhosts.conf`.
2. Добавить в него:

```apache
<VirtualHost *:80>
    ServerName site1.local
    DocumentRoot "C:/xampp/htdocs/site1/Route"

    <Directory "C:/xampp/htdocs/site1/Route">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog "logs/site1-error.log"
    CustomLog "logs/site1-access.log" combined
</VirtualHost>
```

3. Убедиться, что в `C:/xampp/apache/conf/httpd.conf` строка подключения виртуальных хостов не закомментирована:

```apache
Include conf/extra/httpd-vhosts.conf
```

4. Перезапустить Apache через XAMPP Control Panel.

### 4.3. Запуск проекта через виртуальный хост

После перезапуска сервера сайт будет доступен по адресу:

```text
http://site1.local/
```

Apache будет отдавать содержимое папки `C:/xampp/htdocs/site1/Route`.

## 5. Краткая инструкция для пользователя

1. Открыть браузер и перейти на `http://site1.local/`  
   (или `http://localhost/site1/Route/index.php`, если виртуальный хост не настроен).
2. Ввести список городов через `;`.
3. Нажать «Рассчитать маршрут».
4. Посмотреть количество точек, суммарную дистанцию и предложенный тип транспорта.
5. При необходимости перейти по кнопкам «Google Maps» или «Yandex Maps».

## 6. Краткая инструкция для разработчика

- Основной PHP‑код: `route_logic.php`.
- Интерфейс и рендер: `index.php`.
- Стили: `route.css`.
- Документация: `docs/`.

Для локальной разработки достаточно:

1. Установить XAMPP.
2. Скопировать проект в `C:/xampp/htdocs/site1/Route`.
3. При желании настроить виртуальный хост `site1.local`.
4. Работать с кодом и обновлять страницу в браузере.
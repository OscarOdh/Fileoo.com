create table users
(
    id            int auto_increment
        primary key,
    username      varchar(50)                                 not null,
    email         varchar(100)                                null,
    password_hash varchar(255)                                not null,
    selected_css  varchar(255) default 'css/style_Matrix.css' null,
    created_at    timestamp    default current_timestamp()    not null,
    quota_mb      int unsigned default 1000                   not null,
    constraint email
        unique (email),
    constraint username
        unique (username)
)
    collate = utf8mb4_unicode_ci;


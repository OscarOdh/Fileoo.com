create table login_attempts
(
    ip_address   varchar(45)                           not null,
    attempt_time timestamp default current_timestamp() not null
)
    collate = utf8mb4_unicode_ci;

create index idx_ip_time
    on login_attempts (ip_address, attempt_time);


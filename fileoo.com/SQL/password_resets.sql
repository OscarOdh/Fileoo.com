create table password_resets
(
    id         int unsigned auto_increment
        primary key,
    user_id    int                                   not null,
    token_hash varchar(64)                           not null,
    expires_at datetime                              not null,
    created_at timestamp default current_timestamp() not null,
    constraint token_hash
        unique (token_hash),
    constraint fk_password_resets_user
        foreign key (user_id) references users (id)
            on update cascade on delete cascade
)
    collate = utf8mb4_unicode_ci;

create index idx_pr_user_id
    on password_resets (user_id);


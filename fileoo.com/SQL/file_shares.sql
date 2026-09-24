create table file_shares
(
    share_id            int unsigned auto_increment
        primary key,
    file_id             int unsigned                          not null,
    shared_by_user_id   int                                   not null,
    shared_with_user_id int                                   null,
    shared_with_email   varchar(100)                          null,
    share_type          enum ('user', 'email')                not null,
    share_timestamp     timestamp default current_timestamp() not null,
    constraint fk_file_shares_file_id
        foreign key (file_id) references user_files (file_id)
            on update cascade on delete cascade,
    constraint fk_file_shares_shared_by
        foreign key (shared_by_user_id) references users (id)
            on update cascade on delete cascade,
    constraint fk_file_shares_shared_with
        foreign key (shared_with_user_id) references users (id)
            on update cascade on delete set null
)
    collate = utf8mb4_unicode_ci;

create index idx_file_id
    on file_shares (file_id);

create index idx_shared_by_user_id
    on file_shares (shared_by_user_id);

create index idx_shared_with_email
    on file_shares (shared_with_email);

create index idx_shared_with_user_id
    on file_shares (shared_with_user_id);


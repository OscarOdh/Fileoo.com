create table share_links
(
    share_link_id      int unsigned auto_increment
        primary key,
    file_id            int unsigned                          not null,
    created_by_user_id int                                   not null,
    token              varchar(64)                           not null,
    max_downloads      int       default 20                  null,
    download_count     int       default 0                   not null,
    expires_at         datetime                              null,
    created_at         timestamp default current_timestamp() not null,
    passcode_hash      varchar(255)                          null,
    constraint token
        unique (token),
    constraint fk_share_links_file
        foreign key (file_id) references user_files (file_id)
            on update cascade on delete cascade,
    constraint fk_share_links_user
        foreign key (created_by_user_id) references users (id)
            on update cascade on delete cascade
)
    collate = utf8mb4_unicode_ci;

create index idx_sl_created_by
    on share_links (created_by_user_id);

create index idx_sl_file_id
    on share_links (file_id);


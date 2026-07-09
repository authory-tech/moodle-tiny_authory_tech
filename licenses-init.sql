CREATE TABLE institutions (
    id                          SERIAL PRIMARY KEY,
    name                        VARCHAR(255) NOT NULL,
    api_key                     VARCHAR(255) NOT NULL UNIQUE,
    moodle_url                  VARCHAR(255) NOT NULL,
    tier                        VARCHAR(20)  NOT NULL DEFAULT 'trial',
    channel                     VARCHAR(20)  NOT NULL DEFAULT 'direct',
    stripe_customer_id          VARCHAR(255),
    stripe_subscription_id      VARCHAR(255),
    stripe_subscription_status  VARCHAR(50),
    created_at                  TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX institutions_stripe_subscription_id_idx
    ON institutions (stripe_subscription_id)
    WHERE stripe_subscription_id IS NOT NULL;

CREATE TABLE license_pools (
    id              SERIAL PRIMARY KEY,
    institution_id  INTEGER   NOT NULL REFERENCES institutions(id),
    seat_count      INTEGER   NOT NULL,
    starts_at       DATE      NOT NULL,
    ends_at         DATE      NOT NULL,
    stripe_invoice_id VARCHAR(255),
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX license_pools_stripe_invoice_id_idx
    ON license_pools (stripe_invoice_id)
    WHERE stripe_invoice_id IS NOT NULL;

CREATE TABLE student_licenses (
    id              SERIAL PRIMARY KEY,
    pool_id         INTEGER NOT NULL REFERENCES license_pools(id),
    institution_id  INTEGER NOT NULL REFERENCES institutions(id),
    person_id       BIGINT  NOT NULL,
    UNIQUE (pool_id, person_id)
);

-- Keycloak gets its own database on the shared Postgres (dev). Prod: separate DB/instance.
CREATE DATABASE keycloak OWNER lms;

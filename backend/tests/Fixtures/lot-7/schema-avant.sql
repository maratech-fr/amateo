-- Schéma figé d avant la PR 3b du lot 7 (tête 65781150a de lot/conception, 234 migrations), table des versions de
-- migration et lignes posées par les migrations comprises. Ne se recapture jamais : c est la base de départ de
-- Lot7MigrationChainTest. Produit par : pg_dump -U amateo_owner -d amateo_test --no-owner --column-inserts
-- (PostgreSQL 16.14) sur une base de test fraîchement migrée, lignes \restrict / \unrestrict de psql retirées.

--
-- PostgreSQL database dump
--


-- Dumped from database version 16.14
-- Dumped by pg_dump version 16.14

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: club_subscription_tier_required(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.club_subscription_tier_required() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public', 'pg_temp'
    AS $$
BEGIN
    IF NEW.tier_id IS NULL AND EXISTS (SELECT 1 FROM subscription_plan_tier WHERE plan_id = NEW.plan_id) THEN
        RAISE EXCEPTION 'club_subscription: the offer % has tiers, the line needs one of them', NEW.plan_id USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$$;


--
-- Name: league_window_suggestions(uuid); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.league_window_suggestions(p_requesting_club uuid) RETURNS TABLE(category text, level text, gender text, day_of_week smallint, windows jsonb, club_count integer)
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public', 'pg_temp'
    AS $$
    WITH me AS (
        SELECT upper(substring(ffbb_club_code FROM 1 FOR 3)) AS ligue,
               substring(ffbb_club_code FROM 4 FOR 4) AS comite
        FROM public.club
        WHERE id = p_requesting_club
          -- BCK-36 : le demandeur DOIT être le club du tenant courant (prédicat RLS
          -- du dépôt). GUC absent / club ≠ GUC ⇒ me vide ⇒ aucun résultat (fail-closed).
          AND id = NULLIF(current_setting('app.club_id', true), '')::uuid
          AND ffbb_club_code ~ '^[A-Za-z]{3}[0-9]{4}'
    ),
    peers AS (
        SELECT c.id,
               upper(substring(c.ffbb_club_code FROM 1 FOR 3)) AS ligue,
               substring(c.ffbb_club_code FROM 4 FOR 4) AS comite
        FROM public.club c
        WHERE c.id <> p_requesting_club
          -- BCK-36 : les clubs de démonstration ne comptent jamais dans la tendance.
          AND NOT c.is_demo
          AND c.ffbb_club_code ~ '^[A-Za-z]{3}[0-9]{4}'
    ),
    entries AS (
        SELECT w.club_id, w.category, w.level, w.gender, w.day_of_week,
               p.ligue, p.comite,
               jsonb_agg(
                   jsonb_build_object(
                       'kickoffMin', to_char(w.kickoff_min, 'HH24:MI'),
                       'kickoffMax', to_char(w.kickoff_max, 'HH24:MI')
                   )
                   ORDER BY w.kickoff_min, w.kickoff_max
               ) AS windows
        FROM public.club_league_window w
        JOIN public.season s ON s.id = w.season_id AND s.status = 'active'
        JOIN peers p ON p.id = w.club_id
        GROUP BY w.club_id, w.category, w.level, w.gender, w.day_of_week, p.ligue, p.comite
    ),
    scoped AS (
        SELECT e.category, e.level, e.gender, e.day_of_week, e.windows
        FROM entries e, me
        WHERE CASE
            WHEN e.level = 'REGIONAL' THEN e.ligue = me.ligue
            WHEN e.level IN ('NATIONAL', 'ELITE') THEN true
            ELSE e.ligue = me.ligue AND e.comite = me.comite
        END
    ),
    tallies AS (
        SELECT category, level, gender, day_of_week, windows,
               count(*) AS c,
               sum(count(*)) OVER (PARTITION BY category, level, gender, day_of_week) AS total
        FROM scoped
        GROUP BY category, level, gender, day_of_week, windows
    )
    SELECT category, level, gender, day_of_week, windows, c::int
    FROM tallies
    WHERE c >= 3 AND c * 2 > total;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admin_alert_state; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_alert_state (
    check_key character varying(80) NOT NULL,
    status character varying(10) NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    last_alerted_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    CONSTRAINT chk_admin_alert_state_status CHECK (((status)::text = ANY ((ARRAY['ok'::character varying, 'firing'::character varying])::text[])))
);


--
-- Name: admin_audit_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_audit_log (
    id uuid NOT NULL,
    occurred_at timestamp(0) with time zone NOT NULL,
    super_admin_id uuid,
    action character varying(80) NOT NULL,
    route character varying(180) DEFAULT NULL::character varying,
    status_code integer,
    details jsonb NOT NULL
);


--
-- Name: admin_job_run; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_job_run (
    id uuid NOT NULL,
    job_key character varying(80) NOT NULL,
    command_name character varying(180) NOT NULL,
    source character varying(20) NOT NULL,
    status character varying(20) NOT NULL,
    started_at timestamp(0) with time zone NOT NULL,
    finished_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    duration_ms integer,
    exit_code integer,
    super_admin_id uuid,
    scheduled_for timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    arguments json,
    CONSTRAINT chk_admin_job_run_source CHECK (((source)::text = ANY ((ARRAY['scheduled'::character varying, 'cli'::character varying, 'superadmin'::character varying])::text[]))),
    CONSTRAINT chk_admin_job_run_status CHECK (((status)::text = ANY ((ARRAY['running'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'interrupted'::character varying])::text[])))
);


--
-- Name: app_user; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.app_user (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    email character varying(180) NOT NULL,
    password_hash character varying(255) NOT NULL,
    first_name character varying(120) NOT NULL,
    last_name character varying(120) NOT NULL,
    email_verified_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    anonymized_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    last_login_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    inactivity_warned_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    terms_accepted_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    terms_version character varying(20) DEFAULT NULL::character varying,
    pending_email character varying(180) DEFAULT NULL::character varying,
    release_notes_seen_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    demo_active_until timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    orphan_notice_sent_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    is_demo boolean DEFAULT false NOT NULL
);


--
-- Name: audit_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_log (
    id uuid NOT NULL,
    occurred_at timestamp(0) without time zone NOT NULL,
    actor_user_id uuid,
    club_id uuid,
    action character varying(40) NOT NULL,
    entity_type character varying(60) DEFAULT NULL::character varying,
    entity_id character varying(36) DEFAULT NULL::character varying,
    details jsonb NOT NULL
);

ALTER TABLE ONLY public.audit_log FORCE ROW LEVEL SECURITY;


--
-- Name: calendar_entry; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.calendar_entry (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    kind character varying(20) NOT NULL,
    title character varying(180) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_disruptive boolean DEFAULT false NOT NULL,
    period_type character varying(20) DEFAULT NULL::character varying,
    school_holiday_id uuid,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    created_by character varying(80) DEFAULT NULL::character varying,
    parent_entry_id uuid
);

ALTER TABLE ONLY public.calendar_entry FORCE ROW LEVEL SECURITY;


--
-- Name: club; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.club (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    name character varying(180) NOT NULL,
    slug character varying(180) NOT NULL,
    billing_cycle character varying(20) DEFAULT NULL::character varying,
    plan_expires_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    generation_count_season integer NOT NULL,
    school_zone character varying(24) DEFAULT NULL::character varying,
    timezone character varying(64) NOT NULL,
    locale character varying(10) NOT NULL,
    onboarding_completed boolean NOT NULL,
    ffbb_club_code character varying(64) DEFAULT NULL::character varying,
    accent_color character varying(9) DEFAULT NULL::character varying,
    accent_palette json,
    logo_url character varying(255) DEFAULT NULL::character varying,
    league character varying(24) DEFAULT NULL::character varying,
    accent_color_dark character varying(9) DEFAULT NULL::character varying,
    committee_code character varying(24) DEFAULT NULL::character varying,
    contact_phone character varying(32) DEFAULT NULL::character varying,
    contact_email character varying(180) DEFAULT NULL::character varying,
    address character varying(255) DEFAULT NULL::character varying,
    correspondent_name character varying(180) DEFAULT NULL::character varying,
    correspondent_phone character varying(32) DEFAULT NULL::character varying,
    correspondent_email character varying(180) DEFAULT NULL::character varying,
    president_name character varying(180) DEFAULT NULL::character varying,
    president_phone character varying(32) DEFAULT NULL::character varying,
    president_email character varying(180) DEFAULT NULL::character varying,
    main_venue_name character varying(180) DEFAULT NULL::character varying,
    main_venue_address character varying(255) DEFAULT NULL::character varying,
    postal_code character varying(16) DEFAULT NULL::character varying,
    city character varying(120) DEFAULT NULL::character varying,
    website character varying(255) DEFAULT NULL::character varying,
    latitude double precision,
    longitude double precision,
    erasure_scheduled_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    unsubscribed_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    last_activity_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    sport_id uuid,
    ffbb_teams_imported_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    simulated_today date,
    is_demo boolean DEFAULT false NOT NULL,
    output_credits_used integer DEFAULT 0 NOT NULL,
    weekend_alternates boolean DEFAULT false NOT NULL,
    erasure_reminder_sent_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    demo_retained_until date,
    short_name character varying(20) DEFAULT NULL::character varying,
    purge_hold_until timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    CONSTRAINT club_simulated_today_demo_only CHECK (((simulated_today IS NULL) OR is_demo))
);


--
-- Name: COLUMN club.simulated_today; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.club.simulated_today IS '(DC2Type:date_immutable)';


--
-- Name: club_creation_request; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.club_creation_request (
    id uuid NOT NULL,
    token character varying(64) NOT NULL,
    user_id uuid NOT NULL,
    ara character varying(20) NOT NULL,
    club_name character varying(255) NOT NULL,
    club_email character varying(255) DEFAULT NULL::character varying,
    status character varying(20) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    reminded_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    decided_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    created_at timestamp(0) without time zone NOT NULL
);


--
-- Name: club_invitation; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.club_invitation (
    id uuid NOT NULL,
    club_id uuid NOT NULL,
    email character varying(255) NOT NULL,
    role character varying(20) NOT NULL,
    token_hash character varying(64) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    invited_by_user_id uuid NOT NULL,
    CONSTRAINT chk_club_invitation_role CHECK (((role)::text = ANY ((ARRAY['admin'::character varying, 'member'::character varying])::text[])))
);

ALTER TABLE ONLY public.club_invitation FORCE ROW LEVEL SECURITY;


--
-- Name: club_league_window; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.club_league_window (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    league character varying(24) NOT NULL,
    category character varying(40) NOT NULL,
    level character varying(20) NOT NULL,
    gender character varying(10) DEFAULT NULL::character varying,
    day_of_week smallint NOT NULL,
    kickoff_min time(0) without time zone NOT NULL,
    kickoff_max time(0) without time zone NOT NULL
);

ALTER TABLE ONLY public.club_league_window FORCE ROW LEVEL SECURITY;


--
-- Name: club_mailbox_message; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.club_mailbox_message (
    id uuid NOT NULL,
    club_id uuid NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    simulated_date date NOT NULL,
    from_address character varying(255) NOT NULL,
    to_address character varying(1000) NOT NULL,
    subject character varying(998) NOT NULL,
    body_text text,
    body_html text
);

ALTER TABLE ONLY public.club_mailbox_message FORCE ROW LEVEL SECURITY;


--
-- Name: club_subscription; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.club_subscription (
    id uuid NOT NULL,
    club_id uuid NOT NULL,
    season_year integer NOT NULL,
    plan_id uuid NOT NULL,
    paid_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    origin character varying(20) NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    tier_id uuid,
    CONSTRAINT chk_club_subscription_origin CHECK (((origin)::text = ANY ((ARRAY['support'::character varying, 'conversion'::character varying, 'seed'::character varying])::text[])))
);

ALTER TABLE ONLY public.club_subscription FORCE ROW LEVEL SECURITY;


--
-- Name: club_travel_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.club_travel_cache (
    id uuid NOT NULL,
    club_id uuid NOT NULL,
    profile character varying(16) NOT NULL,
    origin_lat numeric(9,5) NOT NULL,
    origin_lon numeric(9,5) NOT NULL,
    dest_lat numeric(9,5) NOT NULL,
    dest_lon numeric(9,5) NOT NULL,
    minutes smallint NOT NULL,
    resolved_at timestamp(0) with time zone NOT NULL
);

ALTER TABLE ONLY public.club_travel_cache FORCE ROW LEVEL SECURITY;


--
-- Name: club_user; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.club_user (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    user_id uuid NOT NULL,
    role character varying(20) NOT NULL,
    joined_at timestamp(0) without time zone NOT NULL,
    is_active boolean NOT NULL,
    deactivated_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    manager_requested_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    manager_request_escalated_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    CONSTRAINT chk_club_user_role CHECK (((role)::text = ANY ((ARRAY['admin'::character varying, 'member'::character varying])::text[])))
);

ALTER TABLE ONLY public.club_user FORCE ROW LEVEL SECURITY;


--
-- Name: coach; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coach (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    max_days_override smallint,
    is_active boolean NOT NULL,
    parent_coach_id uuid,
    is_employee boolean NOT NULL,
    is_vehicled boolean DEFAULT false NOT NULL,
    person_id uuid NOT NULL
);

ALTER TABLE ONLY public.coach FORCE ROW LEVEL SECURITY;


--
-- Name: coach_player_membership; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coach_player_membership (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    coach_id uuid NOT NULL,
    team_id uuid NOT NULL,
    "position" character varying(120) DEFAULT NULL::character varying,
    is_active boolean NOT NULL
);

ALTER TABLE ONLY public.coach_player_membership FORCE ROW LEVEL SECURITY;


--
-- Name: coach_wish; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coach_wish (
    id uuid NOT NULL,
    version integer NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    calendar_entry_id uuid NOT NULL,
    week_start date NOT NULL,
    team_id uuid NOT NULL,
    coach_id uuid,
    slots_wanted smallint DEFAULT 0 NOT NULL,
    unavailable_days json NOT NULL,
    comment text,
    done boolean DEFAULT false NOT NULL,
    wished_days json DEFAULT '[]'::json NOT NULL,
    keep_season_slots boolean DEFAULT false NOT NULL,
    staff_unavailability json DEFAULT '[]'::json NOT NULL
);

ALTER TABLE ONLY public.coach_wish FORCE ROW LEVEL SECURITY;


--
-- Name: coach_wish_campaign; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coach_wish_campaign (
    id uuid NOT NULL,
    version integer NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    calendar_entry_id uuid NOT NULL,
    deadline date NOT NULL,
    weeks json NOT NULL,
    team_ids json NOT NULL,
    last_digest_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    last_reminder_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    final_recap_sent_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone
);

ALTER TABLE ONLY public.coach_wish_campaign FORCE ROW LEVEL SECURITY;


--
-- Name: coach_wish_mutualization; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coach_wish_mutualization (
    id uuid NOT NULL,
    version integer NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    calendar_entry_id uuid NOT NULL,
    team_id uuid NOT NULL,
    coach_id uuid,
    partner_team_ids json NOT NULL,
    shared_slots smallint NOT NULL,
    done boolean DEFAULT false NOT NULL
);

ALTER TABLE ONLY public.coach_wish_mutualization FORCE ROW LEVEL SECURITY;


--
-- Name: coach_wish_token; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coach_wish_token (
    id uuid NOT NULL,
    token character varying(64) NOT NULL,
    campaign_id uuid NOT NULL,
    coach_id uuid NOT NULL,
    club_id uuid NOT NULL,
    responded_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    created_at timestamp(0) with time zone NOT NULL,
    sent_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    team_ids json DEFAULT '[]'::json NOT NULL
);

ALTER TABLE ONLY public.coach_wish_token FORCE ROW LEVEL SECURITY;


--
-- Name: competition; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.competition (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    team_id uuid NOT NULL,
    name character varying(180) NOT NULL,
    competition_type character varying(20) NOT NULL,
    start_date date,
    end_date date,
    fbi_team_label character varying(180) DEFAULT NULL::character varying,
    ffbb_competition_id character varying(64) DEFAULT NULL::character varying,
    ffbb_poule_id character varying(64) DEFAULT NULL::character varying,
    ffbb_poule_name character varying(120) DEFAULT NULL::character varying,
    ffbb_competition_name character varying(180) DEFAULT NULL::character varying,
    expected_matchdays integer,
    ffbb_poule_opponents json,
    entry_deadline date
);

ALTER TABLE ONLY public.competition FORCE ROW LEVEL SECURITY;


--
-- Name: conflict_mail_receipt; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.conflict_mail_receipt (
    id uuid NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    fingerprint character varying(255) NOT NULL,
    coach_id uuid NOT NULL,
    content_digest character varying(40) NOT NULL,
    sent_at timestamp(0) with time zone NOT NULL
);

ALTER TABLE ONLY public.conflict_mail_receipt FORCE ROW LEVEL SECURITY;


--
-- Name: conflict_mailing; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.conflict_mailing (
    id uuid NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    sent_at timestamp(0) with time zone NOT NULL,
    sent_by_user_id uuid,
    recipient_coach_ids json NOT NULL,
    skipped_coach_ids json NOT NULL,
    mailed_count integer NOT NULL
);

ALTER TABLE ONLY public.conflict_mailing FORCE ROW LEVEL SECURITY;


--
-- Name: conflict_resolution; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.conflict_resolution (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    fingerprint character varying(255) NOT NULL,
    status character varying(30) NOT NULL,
    note character varying(500) DEFAULT NULL::character varying,
    updated_by uuid NOT NULL
);

ALTER TABLE ONLY public.conflict_resolution FORCE ROW LEVEL SECURITY;


--
-- Name: constraint; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public."constraint" (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    name character varying(180) NOT NULL,
    description text,
    scope character varying(20) NOT NULL,
    scope_target_id uuid,
    family character varying(20) NOT NULL,
    rule_type character varying(20) NOT NULL,
    config json NOT NULL,
    created_by character varying(80) DEFAULT NULL::character varying,
    source character varying(80) DEFAULT NULL::character varying,
    source_occurrence_id character varying(180) DEFAULT NULL::character varying,
    is_active boolean DEFAULT true NOT NULL,
    sort_order integer NOT NULL,
    calendar_entry_id uuid,
    parent_constraint_id uuid
);

ALTER TABLE ONLY public."constraint" FORCE ROW LEVEL SECURITY;


--
-- Name: constraint_conflict; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.constraint_conflict (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    schedule_id uuid NOT NULL,
    constraint_ids json NOT NULL,
    type character varying(50) NOT NULL,
    description text NOT NULL,
    suggested_resolution text,
    is_resolved boolean DEFAULT false NOT NULL
);


--
-- Name: doctrine_migration_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.doctrine_migration_versions (
    version character varying(191) NOT NULL,
    executed_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    execution_time integer
);


--
-- Name: email_change_token; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.email_change_token (
    id integer NOT NULL,
    user_id uuid NOT NULL,
    hashed_token character varying(64) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone NOT NULL
);


--
-- Name: email_change_token_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.email_change_token ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.email_change_token_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: email_verification_token; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.email_verification_token (
    id integer NOT NULL,
    user_id uuid NOT NULL,
    hashed_token character varying(64) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    ara character varying(20) NOT NULL,
    club_name character varying(255) DEFAULT NULL::character varying
);


--
-- Name: email_verification_token_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.email_verification_token ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.email_verification_token_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: fbi_correction; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fbi_correction (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    fixture_id uuid NOT NULL,
    field character varying(20) NOT NULL,
    app_value character varying(180) DEFAULT NULL::character varying,
    fbi_value character varying(180) DEFAULT NULL::character varying,
    venue_fbi_label character varying(180) DEFAULT NULL::character varying,
    decided_at timestamp(0) with time zone NOT NULL,
    decided_by uuid NOT NULL,
    last_seen_in_fbi_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    closed_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    closed_by character varying(10) DEFAULT NULL::character varying,
    conflict_fingerprint character varying(255) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.fbi_correction FORCE ROW LEVEL SECURITY;


--
-- Name: fbi_ingestion; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fbi_ingestion (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    deposited_at timestamp(0) with time zone NOT NULL,
    source character varying(16) NOT NULL,
    created integer DEFAULT 0 NOT NULL,
    updated integer DEFAULT 0 NOT NULL,
    unchanged integer DEFAULT 0 NOT NULL,
    deviations_count integer DEFAULT 0 NOT NULL
);

ALTER TABLE ONLY public.fbi_ingestion FORCE ROW LEVEL SECURITY;


--
-- Name: feedback; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feedback (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    club_id uuid NOT NULL,
    user_id uuid NOT NULL,
    topic character varying(40) NOT NULL,
    message text NOT NULL,
    context json,
    status character varying(20) NOT NULL,
    treated_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    created_at timestamp(0) with time zone NOT NULL,
    has_screenshot boolean DEFAULT false NOT NULL,
    purged_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone
);

ALTER TABLE ONLY public.feedback FORCE ROW LEVEL SECURITY;


--
-- Name: ffbb_committee; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ffbb_committee (
    id uuid NOT NULL,
    code character varying(24) NOT NULL,
    league_code character varying(24) DEFAULT NULL::character varying,
    name character varying(255) NOT NULL,
    address character varying(255) DEFAULT NULL::character varying,
    postal_code character varying(16) DEFAULT NULL::character varying,
    city character varying(120) DEFAULT NULL::character varying,
    phone character varying(32) DEFAULT NULL::character varying,
    email character varying(180) DEFAULT NULL::character varying,
    logo_url character varying(255) DEFAULT NULL::character varying,
    fetched_at timestamp(0) with time zone NOT NULL,
    website character varying(255) DEFAULT NULL::character varying
);


--
-- Name: ffbb_league; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ffbb_league (
    id uuid NOT NULL,
    code character varying(24) NOT NULL,
    name character varying(255) NOT NULL,
    address character varying(255) DEFAULT NULL::character varying,
    postal_code character varying(16) DEFAULT NULL::character varying,
    city character varying(120) DEFAULT NULL::character varying,
    phone character varying(32) DEFAULT NULL::character varying,
    email character varying(180) DEFAULT NULL::character varying,
    logo_url character varying(255) DEFAULT NULL::character varying,
    fetched_at timestamp(0) with time zone NOT NULL,
    website character varying(255) DEFAULT NULL::character varying
);


--
-- Name: fixture; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fixture (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    team_id uuid NOT NULL,
    competition_id uuid,
    match_date date NOT NULL,
    home_away character varying(10) NOT NULL,
    opponent_label character varying(180) NOT NULL,
    status character varying(20) DEFAULT 'UNPLACED'::character varying NOT NULL,
    venue_id uuid,
    kickoff_time time(0) without time zone DEFAULT NULL::time without time zone,
    external_ref character varying(64) DEFAULT NULL::character varying,
    fbi_venue_label character varying(180) DEFAULT NULL::character varying,
    placement_source character varying(10) DEFAULT NULL::character varying,
    ffbb_rencontre_id character varying(64) DEFAULT NULL::character varying,
    unplaced_reason character varying(32) DEFAULT NULL::character varying,
    opponent_organisme_code character varying(64) DEFAULT NULL::character varying,
    review_state character varying(20) DEFAULT 'NEW'::character varying NOT NULL,
    reviewed_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    pending_deviations json DEFAULT '[]'::json NOT NULL,
    kept_venue_label character varying(180) DEFAULT NULL::character varying,
    fbi_echo json,
    external_division character varying(180) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.fixture FORCE ROW LEVEL SECURITY;


--
-- Name: holiday_reminder_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.holiday_reminder_log (
    id uuid NOT NULL,
    season_id uuid NOT NULL,
    school_holiday_id uuid NOT NULL,
    threshold smallint NOT NULL,
    sent_at timestamp(0) with time zone NOT NULL
);


--
-- Name: implicit_rule_setting; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.implicit_rule_setting (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    rule_key character varying(40) NOT NULL,
    intensity character varying(20) NOT NULL,
    params json,
    schedule_plan_id uuid NOT NULL
);

ALTER TABLE ONLY public.implicit_rule_setting FORCE ROW LEVEL SECURITY;


--
-- Name: league_match_window; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.league_match_window (
    id uuid NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    league character varying(24) NOT NULL,
    category character varying(40) NOT NULL,
    level character varying(20) NOT NULL,
    gender character varying(10) DEFAULT NULL::character varying,
    day_of_week smallint NOT NULL,
    kickoff_min time(0) without time zone NOT NULL,
    kickoff_max time(0) without time zone NOT NULL
);


--
-- Name: match_constraint; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.match_constraint (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    scope character varying(20) NOT NULL,
    scope_target_id uuid,
    rule_type character varying(20) NOT NULL,
    days_of_week json NOT NULL,
    kickoff_min time(0) without time zone DEFAULT NULL::time without time zone,
    kickoff_max time(0) without time zone DEFAULT NULL::time without time zone,
    venue_id uuid
);

ALTER TABLE ONLY public.match_constraint FORCE ROW LEVEL SECURITY;


--
-- Name: match_module_visit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.match_module_visit (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    user_id uuid NOT NULL,
    reference_snapshot json NOT NULL,
    reference_taken_at timestamp(0) with time zone NOT NULL,
    last_opened_at timestamp(0) with time zone NOT NULL
);

ALTER TABLE ONLY public.match_module_visit FORCE ROW LEVEL SECURITY;


--
-- Name: match_placement_run; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.match_placement_run (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid,
    requested_by_user_id uuid NOT NULL,
    status character varying(16) NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    started_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    finished_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    result_data json
);

ALTER TABLE ONLY public.match_placement_run FORCE ROW LEVEL SECURITY;


--
-- Name: opponent_directory; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.opponent_directory (
    id uuid NOT NULL,
    ffbb_organisme_code character varying(64) NOT NULL,
    name character varying(180) NOT NULL,
    city character varying(180) DEFAULT NULL::character varying,
    postal_code character varying(16) DEFAULT NULL::character varying,
    latitude double precision,
    longitude double precision,
    "precision" character varying(8) NOT NULL,
    venue_label character varying(180) DEFAULT NULL::character varying,
    resolved_at timestamp(0) with time zone NOT NULL,
    logo_id character varying(64) DEFAULT NULL::character varying
);


--
-- Name: opponent_venue_link; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.opponent_venue_link (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    opponent_organisme_code character varying(64) NOT NULL,
    fbi_label character varying(180) NOT NULL,
    fbi_label_norm character varying(180) NOT NULL,
    venue_external_ref character varying(64) DEFAULT NULL::character varying,
    venue_label character varying(180) NOT NULL,
    latitude double precision NOT NULL,
    longitude double precision NOT NULL,
    source character varying(10) NOT NULL,
    address character varying(255) DEFAULT NULL::character varying,
    postal_code character varying(16) DEFAULT NULL::character varying,
    city character varying(180) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.opponent_venue_link FORCE ROW LEVEL SECURITY;


--
-- Name: opponent_venue_suggestion; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.opponent_venue_suggestion (
    id uuid NOT NULL,
    ffbb_organisme_code character varying(64) NOT NULL,
    venue_external_ref character varying(64) DEFAULT NULL::character varying,
    venue_label character varying(180) NOT NULL,
    city character varying(180) DEFAULT NULL::character varying,
    postal_code character varying(16) DEFAULT NULL::character varying,
    latitude double precision,
    longitude double precision,
    source character varying(8) NOT NULL,
    chosen_by_count integer DEFAULT 0 NOT NULL,
    last_chosen_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    CONSTRAINT ck_opponent_venue_suggestion_count_non_negative CHECK ((chosen_by_count >= 0))
);


--
-- Name: period_reminder_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.period_reminder_log (
    id uuid NOT NULL,
    calendar_entry_id uuid NOT NULL,
    threshold smallint NOT NULL,
    sent_at timestamp(0) with time zone NOT NULL
);


--
-- Name: person; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.person (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    first_name character varying(120) NOT NULL,
    last_name character varying(120) NOT NULL,
    email character varying(180) DEFAULT NULL::character varying,
    phone character varying(40) DEFAULT NULL::character varying,
    gender character varying(12) DEFAULT 'UNSPECIFIED'::character varying NOT NULL
);

ALTER TABLE ONLY public.person FORCE ROW LEVEL SECURITY;


--
-- Name: place; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.place (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    address character varying(255) DEFAULT NULL::character varying,
    latitude numeric(10,7) DEFAULT NULL::numeric,
    longitude numeric(10,7) DEFAULT NULL::numeric
);

ALTER TABLE ONLY public.place FORCE ROW LEVEL SECURITY;


--
-- Name: place_travel_time; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.place_travel_time (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    place_a_id uuid NOT NULL,
    place_b_id uuid NOT NULL,
    driving_minutes smallint,
    walking_minutes smallint,
    driving_source character varying(10) DEFAULT NULL::character varying,
    walking_source character varying(10) DEFAULT NULL::character varying,
    CONSTRAINT chk_place_travel_time_order CHECK ((place_a_id < place_b_id))
);

ALTER TABLE ONLY public.place_travel_time FORCE ROW LEVEL SECURITY;


--
-- Name: plan_constraint; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_constraint (
    id uuid NOT NULL,
    version integer NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    schedule_plan_id uuid NOT NULL,
    constraint_id uuid NOT NULL,
    applies boolean NOT NULL
);

ALTER TABLE ONLY public.plan_constraint FORCE ROW LEVEL SECURITY;


--
-- Name: plan_team; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_team (
    id uuid NOT NULL,
    version integer NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    schedule_plan_id uuid NOT NULL,
    team_id uuid NOT NULL,
    participates boolean NOT NULL,
    sessions_per_week integer NOT NULL,
    max_sessions_per_day smallint NOT NULL,
    source character varying(30) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.plan_team FORCE ROW LEVEL SECURITY;


--
-- Name: plan_venue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plan_venue (
    id uuid NOT NULL,
    version integer NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    schedule_plan_id uuid NOT NULL,
    venue_id uuid NOT NULL,
    participates boolean NOT NULL,
    closure_lifted boolean DEFAULT false NOT NULL,
    day_overrides json
);

ALTER TABLE ONLY public.plan_venue FORCE ROW LEVEL SECURITY;


--
-- Name: priority_tier; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.priority_tier (
    id integer NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    label character varying(1) NOT NULL,
    name character varying(120) NOT NULL,
    color character varying(20) NOT NULL,
    or_tools_weight integer NOT NULL,
    default_min_sessions integer NOT NULL
);


--
-- Name: public_holiday; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.public_holiday (
    id uuid NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    zone character varying(24) NOT NULL,
    holiday_date date NOT NULL,
    label character varying(120) NOT NULL
);


--
-- Name: release_note; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.release_note (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    title character varying(160) NOT NULL,
    body text NOT NULL,
    note_date date NOT NULL,
    published_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    product_version character varying(40) DEFAULT NULL::character varying,
    body_rich jsonb
);


--
-- Name: reservation; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reservation (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    team_id uuid,
    venue_id uuid NOT NULL,
    day_of_week smallint NOT NULL,
    start_time time(0) without time zone NOT NULL,
    duration_minutes smallint NOT NULL,
    schedule_plan_id uuid NOT NULL,
    label character varying(40) DEFAULT NULL::character varying,
    coach_late_tolerance_minutes smallint DEFAULT 0 NOT NULL
);

ALTER TABLE ONLY public.reservation FORCE ROW LEVEL SECURITY;


--
-- Name: reset_password_request; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reset_password_request (
    id integer NOT NULL,
    selector character varying(20) NOT NULL,
    hashed_token character varying(100) NOT NULL,
    requested_at timestamp(0) without time zone NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    user_id uuid NOT NULL
);


--
-- Name: reset_password_request_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.reset_password_request ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.reset_password_request_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: schedule; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schedule (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    name character varying(180) NOT NULL,
    status character varying(30) NOT NULL,
    score integer,
    solver_seed integer NOT NULL,
    snapshot_hash character varying(128) DEFAULT NULL::character varying,
    snapshot_data json NOT NULL,
    solver_version character varying(80) DEFAULT NULL::character varying,
    constraint_version character varying(80) DEFAULT NULL::character varying,
    score_formula_version character varying(80) DEFAULT NULL::character varying,
    solver_timeout_seconds integer,
    solver_nb_variables integer,
    solver_nb_constraints integer,
    solver_nb_conflicts integer,
    solver_wall_time_ms integer,
    pdf_export_status character varying(30) DEFAULT NULL::character varying,
    pdf_export_url character varying(2048) DEFAULT NULL::character varying,
    schedule_plan_id uuid NOT NULL,
    version_number integer NOT NULL,
    manually_edited_since_generation boolean DEFAULT false NOT NULL,
    queued_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    solve_started_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    payload_graft json,
    pdf_export_request_id character varying(32) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.schedule FORCE ROW LEVEL SECURITY;


--
-- Name: schedule_diagnostic; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schedule_diagnostic (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    schedule_id uuid NOT NULL,
    type character varying(50) NOT NULL,
    severity character varying(20) NOT NULL,
    team_id uuid,
    coach_id uuid,
    venue_id uuid,
    message text NOT NULL,
    suggestions json NOT NULL,
    day_of_week smallint,
    start_time character varying(5) DEFAULT NULL::character varying,
    rule_key character varying(40) DEFAULT NULL::character varying,
    causes json NOT NULL,
    open_candidates smallint
);

ALTER TABLE ONLY public.schedule_diagnostic FORCE ROW LEVEL SECURITY;


--
-- Name: schedule_plan; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schedule_plan (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    type character varying(20) NOT NULL,
    name character varying(180) NOT NULL,
    start_date timestamp(0) with time zone NOT NULL,
    end_date timestamp(0) with time zone NOT NULL,
    calendar_entry_id uuid,
    chosen_schedule_id uuid,
    last_version_number integer DEFAULT 0 NOT NULL,
    first_chosen_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    lock_source_schedule_id uuid,
    auto_validated_date date,
    alert_seen_date date,
    alert_seen_kind character varying(32) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.schedule_plan FORCE ROW LEVEL SECURITY;


--
-- Name: schedule_slot_template; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schedule_slot_template (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    schedule_id uuid NOT NULL,
    team_id uuid NOT NULL,
    venue_id uuid NOT NULL,
    coach_id uuid,
    day_of_week smallint NOT NULL,
    start_time time(0) without time zone NOT NULL,
    duration_minutes smallint NOT NULL,
    lock_level character varying(10) NOT NULL,
    pending_constraint_suggestion json,
    lock_origin character varying(20) DEFAULT NULL::character varying,
    shared_training_block_id uuid,
    coach_late_tolerance_minutes smallint DEFAULT 0 NOT NULL,
    CONSTRAINT chk_schedule_slot_template_lock_origin CHECK (((lock_origin IS NULL) OR ((lock_origin)::text = ANY ((ARRAY['RESERVATION'::character varying, 'MANUAL'::character varying, 'UNKNOWN'::character varying])::text[]))))
);

ALTER TABLE ONLY public.schedule_slot_template FORCE ROW LEVEL SECURITY;


--
-- Name: schedule_state; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schedule_state (
    id uuid NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    schedule_id uuid NOT NULL,
    data json NOT NULL
);

ALTER TABLE ONLY public.schedule_state FORCE ROW LEVEL SECURITY;


--
-- Name: scheduled_deployment; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.scheduled_deployment (
    id uuid NOT NULL,
    version character varying(64) NOT NULL,
    scheduled_at timestamp(0) with time zone NOT NULL,
    expected_return_at timestamp(0) with time zone NOT NULL,
    status character varying(20) NOT NULL,
    created_by uuid NOT NULL,
    dispatched_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    github_run_id bigint,
    failure_reason text,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    dispatch_note text,
    run_lookup_after timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    CONSTRAINT chk_scheduled_deployment_status CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'dispatched'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: school_holiday_period; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_holiday_period (
    id uuid NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    zone character varying(24) NOT NULL,
    label character varying(120) NOT NULL,
    holiday_type character varying(40) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    school_year character varying(9) NOT NULL
);


--
-- Name: season; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.season (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    name character varying(120) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    status character varying(20) NOT NULL,
    export_pdf_url character varying(2048) DEFAULT NULL::character varying,
    transition_data json NOT NULL
);

ALTER TABLE ONLY public.season FORCE ROW LEVEL SECURITY;


--
-- Name: shared_competition_deadline; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shared_competition_deadline (
    id uuid NOT NULL,
    ffbb_competition_id character varying(64) NOT NULL,
    entry_deadline date NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL
);


--
-- Name: shared_training_block; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shared_training_block (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    schedule_plan_id uuid NOT NULL,
    common_sessions integer NOT NULL,
    label character varying(40) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.shared_training_block FORCE ROW LEVEL SECURITY;


--
-- Name: shared_training_block_team; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shared_training_block_team (
    id uuid NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    schedule_plan_id uuid NOT NULL,
    block_id uuid NOT NULL,
    team_id uuid NOT NULL
);

ALTER TABLE ONLY public.shared_training_block_team FORCE ROW LEVEL SECURITY;


--
-- Name: solver_metrics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.solver_metrics (
    id uuid NOT NULL,
    schedule_id uuid NOT NULL,
    club_id uuid NOT NULL,
    status character varying(30) NOT NULL,
    wall_time_ms integer,
    nb_variables integer,
    nb_constraints integer,
    nb_conflicts integer,
    score integer,
    solver_version character varying(80) DEFAULT NULL::character varying,
    created_at timestamp(0) with time zone NOT NULL,
    plan_type character varying(20) DEFAULT NULL::character varying,
    nb_teams integer,
    nb_venues integer,
    queued_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    solve_started_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone,
    payload_bytes integer,
    total_wall_time_ms integer,
    cpu_time_ms integer,
    workers integer,
    budget_seconds integer,
    solver_status_detail character varying(20) DEFAULT NULL::character varying,
    peak_rss_mb double precision,
    rss_before_mb double precision,
    engine_wait_ms integer
);

ALTER TABLE ONLY public.solver_metrics FORCE ROW LEVEL SECURITY;


--
-- Name: sport; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sport (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    name character varying(120) NOT NULL,
    slug character varying(120) NOT NULL,
    icon character varying(80) DEFAULT NULL::character varying,
    is_active boolean NOT NULL
);


--
-- Name: sport_category; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sport_category (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid,
    sport_id uuid NOT NULL,
    name character varying(120) NOT NULL,
    is_custom boolean NOT NULL,
    age_min integer,
    age_max integer,
    sort_order integer NOT NULL,
    gender character varying(10) DEFAULT NULL::character varying,
    match_minutes integer,
    warmup_minutes integer
);

ALTER TABLE ONLY public.sport_category FORCE ROW LEVEL SECURITY;


--
-- Name: subscription_plan; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_plan (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    name character varying(120) NOT NULL,
    max_venues integer NOT NULL,
    max_generations integer NOT NULL,
    features json NOT NULL,
    code character varying(60) DEFAULT NULL::character varying NOT NULL
);


--
-- Name: subscription_plan_tier; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_plan_tier (
    id uuid NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    plan_id uuid NOT NULL,
    code character varying(60) NOT NULL,
    "position" integer NOT NULL,
    max_teams integer NOT NULL,
    price_cents integer
);


--
-- Name: super_admin; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.super_admin (
    id uuid NOT NULL,
    email character varying(180) NOT NULL,
    password_hash character varying(255) NOT NULL,
    totp_secret text NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    last_login_at timestamp(0) with time zone DEFAULT NULL::timestamp with time zone
);


--
-- Name: team; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.team (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    sport_category_id uuid NOT NULL,
    priority_tier_id integer NOT NULL,
    name character varying(180) NOT NULL,
    gender character varying(10) DEFAULT NULL::character varying,
    min_sessions_override smallint,
    match_day smallint,
    forced_venue_id uuid,
    is_active boolean NOT NULL,
    parent_team_id uuid,
    level character varying(20) DEFAULT NULL::character varying,
    size character varying(20) DEFAULT NULL::character varying,
    tier_order integer DEFAULT 0 NOT NULL,
    is_ctc boolean DEFAULT false NOT NULL,
    ctc_partner character varying(120) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.team FORCE ROW LEVEL SECURITY;


--
-- Name: team_coach; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.team_coach (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    team_id uuid NOT NULL,
    coach_id uuid NOT NULL,
    role character varying(20) NOT NULL,
    is_required boolean DEFAULT true NOT NULL,
    wish_recipient boolean DEFAULT false NOT NULL
);

ALTER TABLE ONLY public.team_coach FORCE ROW LEVEL SECURITY;


--
-- Name: team_link; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.team_link (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    team_a_id uuid NOT NULL,
    team_b_id uuid NOT NULL,
    link_type character varying(20) NOT NULL,
    training_intensity character varying(20) DEFAULT 'PREFERRED'::character varying NOT NULL
);

ALTER TABLE ONLY public.team_link FORCE ROW LEVEL SECURITY;


--
-- Name: team_match_habit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.team_match_habit (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    team_id uuid NOT NULL,
    day_of_week smallint NOT NULL,
    kickoff_time time(0) without time zone NOT NULL,
    venue_id uuid,
    week character varying(8) DEFAULT 'A'::character varying NOT NULL
);

ALTER TABLE ONLY public.team_match_habit FORCE ROW LEVEL SECURITY;


--
-- Name: transition_reminder_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transition_reminder_log (
    id uuid NOT NULL,
    season_id uuid NOT NULL,
    threshold smallint NOT NULL,
    sent_at timestamp(0) with time zone NOT NULL
);


--
-- Name: venue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.venue (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    name character varying(180) NOT NULL,
    is_external boolean DEFAULT false NOT NULL,
    color character varying(20) DEFAULT NULL::character varying,
    source character varying(20) NOT NULL,
    external_ref character varying(180) DEFAULT NULL::character varying,
    is_active boolean NOT NULL,
    parent_venue_id uuid,
    can_split boolean DEFAULT false NOT NULL,
    external_labels json DEFAULT '[]'::json NOT NULL,
    place_id uuid NOT NULL
);

ALTER TABLE ONLY public.venue FORCE ROW LEVEL SECURITY;


--
-- Name: venue_match_window; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.venue_match_window (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    venue_id uuid NOT NULL,
    day_of_week smallint,
    start_time time(0) without time zone NOT NULL,
    end_time time(0) without time zone NOT NULL,
    applies_on_public_holidays boolean DEFAULT false NOT NULL,
    opening_date date,
    label character varying(120) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.venue_match_window FORCE ROW LEVEL SECURITY;


--
-- Name: venue_occupancy; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.venue_occupancy (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    venue_id uuid NOT NULL,
    day_of_week smallint NOT NULL,
    start_time time(0) without time zone NOT NULL,
    duration_minutes integer NOT NULL,
    label character varying(40) NOT NULL,
    schedule_plan_id uuid NOT NULL
);

ALTER TABLE ONLY public.venue_occupancy FORCE ROW LEVEL SECURITY;


--
-- Name: venue_training_slot; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.venue_training_slot (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    venue_id uuid NOT NULL,
    day_of_week smallint NOT NULL,
    start_time time(0) without time zone NOT NULL,
    duration_minutes integer NOT NULL,
    capacity integer DEFAULT 1 NOT NULL,
    schedule_plan_id uuid NOT NULL,
    group_label character varying(40) DEFAULT NULL::character varying
);

ALTER TABLE ONLY public.venue_training_slot FORCE ROW LEVEL SECURITY;


--
-- Name: venue_travel_rule_setting; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.venue_travel_rule_setting (
    id uuid NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    updated_at timestamp(0) with time zone NOT NULL,
    club_id uuid NOT NULL,
    season_id uuid NOT NULL,
    intensity character varying(20) DEFAULT 'PREFERRED'::character varying NOT NULL,
    tolerance_minutes integer DEFAULT 20 NOT NULL,
    default_minutes integer DEFAULT 20 NOT NULL
);

ALTER TABLE ONLY public.venue_travel_rule_setting FORCE ROW LEVEL SECURITY;


--
-- Data for Name: admin_alert_state; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: admin_audit_log; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: admin_job_run; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: app_user; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: audit_log; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: calendar_entry; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: club; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: club_creation_request; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: club_invitation; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: club_league_window; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: club_mailbox_message; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: club_subscription; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: club_travel_cache; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: club_user; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: coach; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: coach_player_membership; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: coach_wish; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: coach_wish_campaign; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: coach_wish_mutualization; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: coach_wish_token; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: competition; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: conflict_mail_receipt; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: conflict_mailing; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: conflict_resolution; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: constraint; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: constraint_conflict; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: doctrine_migration_versions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260608232552', '2026-11-26 23:11:45', 154);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260610141312', '2026-11-26 23:11:45', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260611120000', '2026-11-26 23:11:45', 33);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260611130000', '2026-11-26 23:11:45', 30);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260613230512', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260615010708', '2026-11-26 23:11:45', 25);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260615011852', '2026-11-26 23:11:45', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260615012129', '2026-11-26 23:11:45', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260615012531', '2026-11-26 23:11:45', 6);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260615012600', '2026-11-26 23:11:45', 11);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260615114943', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260615234656', '2026-11-26 23:11:45', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260616203213', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260617082000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260617162122', '2026-11-26 23:11:45', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260619000000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260619125003', '2026-11-26 23:11:45', 6);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260621202454', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260622091449', '2026-11-26 23:11:45', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260701095535', '2026-11-26 23:11:45', 6);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260701124929', '2026-11-26 23:11:45', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260701155233', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260702095041', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260702101819', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260703120000', '2026-11-26 23:11:45', 17);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260704120000', '2026-11-26 23:11:45', 11);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260704140000', '2026-11-26 23:11:45', 7);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260705120000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260705140000', '2026-11-26 23:11:45', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260706120000', '2026-11-26 23:11:45', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260706130000', '2026-11-26 23:11:45', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260706220000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260706230000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260706240000', '2026-11-26 23:11:45', 17);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260706250000', '2026-11-26 23:11:45', 7);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260707100000', '2026-11-26 23:11:45', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260707120000', '2026-11-26 23:11:45', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260707130000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260708090000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260709120000', '2026-11-26 23:11:45', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260709130000', '2026-11-26 23:11:45', 8);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260710120000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260710150000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260710170000', '2026-11-26 23:11:45', 9);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260710180000', '2026-11-26 23:11:45', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260710200000', '2026-11-26 23:11:45', 15);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260711120000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260711140000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260711160000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260711180000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260711200000', '2026-11-26 23:11:45', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260711220000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260711230000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260711240000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260712090000', '2026-11-26 23:11:45', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260712110000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260712120000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260712130000', '2026-11-26 23:11:45', 8);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260712150000', '2026-11-26 23:11:45', 15);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260716100000', '2026-11-26 23:11:45', 16);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260716110000', '2026-11-26 23:11:45', 11);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260716120000', '2026-11-26 23:11:45', 8);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260716130000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260716150000', '2026-11-26 23:11:45', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260716180000', '2026-11-26 23:11:45', 6);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260717020000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260717100000', '2026-11-26 23:11:45', 13);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260717140000', '2026-11-26 23:11:45', 6);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260717160000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260717180000', '2026-11-26 23:11:45', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260718120000', '2026-11-26 23:11:45', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260718150000', '2026-11-26 23:11:45', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260718170000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260718200000', '2026-11-26 23:11:45', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260718210000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260718220000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260724140000', '2026-11-26 23:11:45', 7);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260725120000', '2026-11-26 23:11:45', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260726100000', '2026-11-26 23:11:45', 21);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260727100000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260727120000', '2026-11-26 23:11:45', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260731090000', '2026-11-26 23:11:45', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260801120000', '2026-11-26 23:11:45', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260801150000', '2026-11-26 23:11:45', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260802140000', '2026-11-26 23:11:45', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260802180000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260803090000', '2026-11-26 23:11:45', 15);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260803150000', '2026-11-26 23:11:45', 20);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260803190000', '2026-11-26 23:11:45', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260803210000', '2026-11-26 23:11:45', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260804120000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260804150000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260804170000', '2026-11-26 23:11:45', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260804210000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260804230000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260805000000', '2026-11-26 23:11:45', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260807000000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260807010000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260807120000', '2026-11-26 23:11:45', 7);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260807170000', '2026-11-26 23:11:45', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260807190000', '2026-11-26 23:11:45', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260809160000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260810120000', '2026-11-26 23:11:45', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260810140000', '2026-11-26 23:11:45', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260810160000', '2026-11-26 23:11:45', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260812120000', '2026-11-26 23:11:46', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260812130000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260812140000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260812150000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260813120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260813130000', '2026-11-26 23:11:46', 11);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260813140000', '2026-11-26 23:11:46', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260813150000', '2026-11-26 23:11:46', 7);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260813160000', '2026-11-26 23:11:46', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260814120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260814130000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260814140000', '2026-11-26 23:11:46', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260815120000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260815130000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260816120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260817120000', '2026-11-26 23:11:46', 16);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260817130000', '2026-11-26 23:11:46', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260818120000', '2026-11-26 23:11:46', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260821120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260822120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260823120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260824120000', '2026-11-26 23:11:46', 8);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260824130000', '2026-11-26 23:11:46', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260824140000', '2026-11-26 23:11:46', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260825120000', '2026-11-26 23:11:46', 19);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260825140000', '2026-11-26 23:11:46', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260826120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260826130000', '2026-11-26 23:11:46', 15);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260826140000', '2026-11-26 23:11:46', 8);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260827120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260827150000', '2026-11-26 23:11:46', 7);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260828120000', '2026-11-26 23:11:46', 9);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260828160000', '2026-11-26 23:11:46', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260828170000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260831120000', '2026-11-26 23:11:46', 17);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260831130000', '2026-11-26 23:11:46', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260908120000', '2026-11-26 23:11:46', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260909120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260912120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260915120000', '2026-11-26 23:11:46', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260915130000', '2026-11-26 23:11:46', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260915140000', '2026-11-26 23:11:46', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260916120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260918200000', '2026-11-26 23:11:46', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260919120000', '2026-11-26 23:11:46', 11);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260919121000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260920120000', '2026-11-26 23:11:46', 14);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260920130000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260920140000', '2026-11-26 23:11:46', 15);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260921120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260922120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260928120000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260928130000', '2026-11-26 23:11:46', 9);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260928140000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260929120000', '2026-11-26 23:11:46', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260929130000', '2026-11-26 23:11:46', 6);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260929160000', '2026-11-26 23:11:46', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260929170000', '2026-11-26 23:11:46', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260930090000', '2026-11-26 23:11:46', 31);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260930100000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260930110000', '2026-11-26 23:11:46', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20260930120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261002090000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261002120000', '2026-11-26 23:11:46', 11);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261003090000', '2026-11-26 23:11:46', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261003100000', '2026-11-26 23:11:46', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261003110000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261004120000', '2026-11-26 23:11:46', 11);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261004130000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261004140000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261004150000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261005100000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261005110000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261006120000', '2026-11-26 23:11:46', 13);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261006130000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261006140000', '2026-11-26 23:11:46', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261008100000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261008120000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261008140000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261009120000', '2026-11-26 23:11:46', 11);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261009140000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261010100000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261010120000', '2026-11-26 23:11:46', 6);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261010150000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261010160000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261010170000', '2026-11-26 23:11:46', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261011120000', '2026-11-26 23:11:46', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261011130000', '2026-11-26 23:11:46', 3);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261018120000', '2026-11-26 23:11:46', 8);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261019120000', '2026-11-26 23:11:46', 0);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261024120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261024130000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261025120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261025130000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261027120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261028120000', '2026-11-26 23:11:46', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261101120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261102120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261102121000', '2026-11-26 23:11:46', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261103120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261103140000', '2026-11-26 23:11:46', 9);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261104100000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261108120000', '2026-11-26 23:11:46', 4);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261109120000', '2026-11-26 23:11:46', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261109140000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261110120000', '2026-11-26 23:11:46', 10);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261110130000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261110140000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261111120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261111180000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261112090000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261117120000', '2026-11-26 23:11:46', 2);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261117130000', '2026-11-26 23:11:46', 11);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261117140000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261120120000', '2026-11-26 23:11:46', 5);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261121120000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261121130000', '2026-11-26 23:11:46', 1);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261122120000', '2026-11-26 23:11:46', 6);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261122140000', '2026-11-26 23:11:46', 18);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261123120000', '2026-11-26 23:11:46', 20);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261124120000', '2026-11-26 23:11:46', 20);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261125120000', '2026-11-26 23:11:46', 18);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261126120000', '2026-11-26 23:11:46', 20);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261127120000', '2026-11-26 23:11:46', 18);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261128120000', '2026-11-26 23:11:46', 23);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261129120000', '2026-11-26 23:11:46', 19);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261130120000', '2026-11-26 23:11:46', 17);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261201120000', '2026-11-26 23:11:46', 12);
INSERT INTO public.doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261202120000', '2026-11-26 23:11:46', 3);


--
-- Data for Name: email_change_token; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: email_verification_token; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: fbi_correction; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: fbi_ingestion; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: feedback; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: ffbb_committee; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: ffbb_league; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: fixture; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: holiday_reminder_log; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: implicit_rule_setting; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: league_match_window; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('71da5619-e200-457f-a35f-beb4c1475c24', '2026-11-26 23:11:46+00', 'AURA', 'U9', 'DEPARTEMENTAL', NULL, 6, '09:30:00', '17:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('11f6ccd3-4026-49e3-afc7-d8ddf0bdd29d', '2026-11-26 23:11:46+00', 'AURA', 'U11', 'DEPARTEMENTAL', NULL, 6, '09:30:00', '17:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('5cde6008-d0c5-438c-8dcc-b7aa9d1911ed', '2026-11-26 23:11:46+00', 'AURA', 'U13', 'DEPARTEMENTAL', NULL, 6, '13:00:00', '18:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('a2eeb52e-ebdf-4583-a347-d02485cf760b', '2026-11-26 23:11:46+00', 'AURA', 'U15', 'DEPARTEMENTAL', NULL, 6, '13:00:00', '18:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('68ab85b4-610c-472a-a1b9-0c35103af716', '2026-11-26 23:11:46+00', 'AURA', 'U15', 'DEPARTEMENTAL', NULL, 7, '08:30:00', '16:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('66e4d25b-d1cd-4571-91e6-fa10f0b44447', '2026-11-26 23:11:46+00', 'AURA', 'U18', 'DEPARTEMENTAL', NULL, 6, '13:30:00', '19:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('bfc3075b-e586-4ae5-8529-88df94133bad', '2026-11-26 23:11:46+00', 'AURA', 'U18', 'DEPARTEMENTAL', NULL, 7, '08:30:00', '16:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('7ee33b5a-4a86-4391-bf08-038fe608433e', '2026-11-26 23:11:46+00', 'AURA', 'U21', 'DEPARTEMENTAL', NULL, 5, '20:00:00', '20:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('d3f69758-02aa-4771-91ce-9193a383e8e7', '2026-11-26 23:11:46+00', 'AURA', 'U21', 'DEPARTEMENTAL', NULL, 6, '13:00:00', '20:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('1d0e6715-c590-438f-bded-539743834fe3', '2026-11-26 23:11:46+00', 'AURA', 'U21', 'DEPARTEMENTAL', NULL, 7, '08:30:00', '17:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('433315d2-8962-4e23-b351-2053d14098a5', '2026-11-26 23:11:46+00', 'AURA', 'SENIOR', 'DEPARTEMENTAL', NULL, 5, '20:00:00', '21:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('8af01fe6-65c0-49cf-92c1-6fadad27edee', '2026-11-26 23:11:46+00', 'AURA', 'SENIOR', 'DEPARTEMENTAL', NULL, 6, '17:00:00', '21:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('6751c749-26aa-4613-897e-86e74570c2fa', '2026-11-26 23:11:46+00', 'AURA', 'SENIOR', 'DEPARTEMENTAL', NULL, 7, '08:00:00', '17:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('7c0eb2d3-1e7c-4639-81ce-58b8f04f9b7e', '2026-11-26 23:11:46+00', 'AURA', 'U13', 'REGIONAL', NULL, 6, '13:00:00', '18:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('694c5f07-e80a-4b68-8b22-01a7414d44aa', '2026-11-26 23:11:46+00', 'AURA', 'U15', 'REGIONAL', NULL, 6, '13:00:00', '18:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('7aa2e30c-075e-40a2-b47c-5771ec29c1ed', '2026-11-26 23:11:46+00', 'AURA', 'U15', 'REGIONAL', NULL, 7, '10:00:00', '16:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('0999734a-bfb3-47e0-8544-d07705c3b4d2', '2026-11-26 23:11:46+00', 'AURA', 'U18', 'REGIONAL', NULL, 7, '10:00:00', '16:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('c5ac7bd6-a2d2-4fcd-b52d-b5d1d0ff4e89', '2026-11-26 23:11:46+00', 'AURA', 'U18', 'REGIONAL', 'M', 7, '10:00:00', '17:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('6b141660-147d-428a-af93-3de93589acbb', '2026-11-26 23:11:46+00', 'AURA', 'U21', 'REGIONAL', NULL, 7, '10:00:00', '17:30:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('c6171b98-9d3c-47d7-92e5-a106519899fb', '2026-11-26 23:11:46+00', 'AURA', 'SENIOR', 'REGIONAL', NULL, 6, '17:30:00', '21:00:00');
INSERT INTO public.league_match_window (id, created_at, league, category, level, gender, day_of_week, kickoff_min, kickoff_max) VALUES ('890aa8b5-a123-48d8-8b5e-4d76af490368', '2026-11-26 23:11:46+00', 'AURA', 'SENIOR', 'REGIONAL', NULL, 7, '10:00:00', '17:30:00');


--
-- Data for Name: match_constraint; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: match_module_visit; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: match_placement_run; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: opponent_directory; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: opponent_venue_link; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: opponent_venue_suggestion; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: period_reminder_log; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: person; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: place; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: place_travel_time; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: plan_constraint; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: plan_team; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: plan_venue; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: priority_tier; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: public_holiday; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: release_note; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: reservation; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: reset_password_request; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: schedule; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: schedule_diagnostic; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: schedule_plan; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: schedule_slot_template; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: schedule_state; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: scheduled_deployment; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: school_holiday_period; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: season; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: shared_competition_deadline; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: shared_training_block; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: shared_training_block_team; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: solver_metrics; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: sport; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: sport_category; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: subscription_plan; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.subscription_plan (id, version, created_at, updated_at, name, max_venues, max_generations, features, code) VALUES ('00000000-0000-4000-8000-000000000001', 1, '2026-11-26 23:11:46+00', '2026-11-26 23:11:46+00', 'Découverte', 0, 10, '[]', 'decouverte');
INSERT INTO public.subscription_plan (id, version, created_at, updated_at, name, max_venues, max_generations, features, code) VALUES ('00000000-0000-4000-8000-000000000006', 1, '2026-11-26 23:11:46+00', '2026-11-26 23:11:46+00', 'Bêta', 0, 0, '[]', 'beta');
INSERT INTO public.subscription_plan (id, version, created_at, updated_at, name, max_venues, max_generations, features, code) VALUES ('00000000-0000-4000-8000-000000000002', 1, '2026-11-26 23:11:46+00', '2026-11-26 23:11:47+00', 'Offre complète', 0, 0, '[]', 'complete');


--
-- Data for Name: subscription_plan_tier; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.subscription_plan_tier (id, created_at, updated_at, plan_id, code, "position", max_teams, price_cents) VALUES ('00000000-0000-4000-8000-000000000101', '2026-11-26 23:11:47+00', '2026-11-26 23:11:47+00', '00000000-0000-4000-8000-000000000002', 'palier-1', 1, 20, NULL);
INSERT INTO public.subscription_plan_tier (id, created_at, updated_at, plan_id, code, "position", max_teams, price_cents) VALUES ('00000000-0000-4000-8000-000000000102', '2026-11-26 23:11:47+00', '2026-11-26 23:11:47+00', '00000000-0000-4000-8000-000000000002', 'palier-2', 2, 34, NULL);
INSERT INTO public.subscription_plan_tier (id, created_at, updated_at, plan_id, code, "position", max_teams, price_cents) VALUES ('00000000-0000-4000-8000-000000000103', '2026-11-26 23:11:47+00', '2026-11-26 23:11:47+00', '00000000-0000-4000-8000-000000000002', 'palier-3', 3, 0, NULL);


--
-- Data for Name: super_admin; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: team; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: team_coach; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: team_link; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: team_match_habit; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: transition_reminder_log; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: venue; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: venue_match_window; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: venue_occupancy; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: venue_training_slot; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: venue_travel_rule_setting; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Name: email_change_token_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.email_change_token_id_seq', 1, false);


--
-- Name: email_verification_token_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.email_verification_token_id_seq', 1, false);


--
-- Name: reset_password_request_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.reset_password_request_id_seq', 1, false);


--
-- Name: admin_alert_state admin_alert_state_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_alert_state
    ADD CONSTRAINT admin_alert_state_pkey PRIMARY KEY (check_key);


--
-- Name: admin_audit_log admin_audit_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_audit_log
    ADD CONSTRAINT admin_audit_log_pkey PRIMARY KEY (id);


--
-- Name: admin_job_run admin_job_run_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_job_run
    ADD CONSTRAINT admin_job_run_pkey PRIMARY KEY (id);


--
-- Name: app_user app_user_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_user
    ADD CONSTRAINT app_user_pkey PRIMARY KEY (id);


--
-- Name: audit_log audit_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_pkey PRIMARY KEY (id);


--
-- Name: calendar_entry calendar_entry_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.calendar_entry
    ADD CONSTRAINT calendar_entry_pkey PRIMARY KEY (id);


--
-- Name: club_creation_request club_creation_request_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_creation_request
    ADD CONSTRAINT club_creation_request_pkey PRIMARY KEY (id);


--
-- Name: club_invitation club_invitation_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_invitation
    ADD CONSTRAINT club_invitation_pkey PRIMARY KEY (id);


--
-- Name: club_league_window club_league_window_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_league_window
    ADD CONSTRAINT club_league_window_pkey PRIMARY KEY (id);


--
-- Name: club_mailbox_message club_mailbox_message_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_mailbox_message
    ADD CONSTRAINT club_mailbox_message_pkey PRIMARY KEY (id);


--
-- Name: club club_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club
    ADD CONSTRAINT club_pkey PRIMARY KEY (id);


--
-- Name: club_subscription club_subscription_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_subscription
    ADD CONSTRAINT club_subscription_pkey PRIMARY KEY (id);


--
-- Name: club_travel_cache club_travel_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_travel_cache
    ADD CONSTRAINT club_travel_cache_pkey PRIMARY KEY (id);


--
-- Name: club_user club_user_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_user
    ADD CONSTRAINT club_user_pkey PRIMARY KEY (id);


--
-- Name: coach coach_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coach
    ADD CONSTRAINT coach_pkey PRIMARY KEY (id);


--
-- Name: coach_player_membership coach_player_membership_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coach_player_membership
    ADD CONSTRAINT coach_player_membership_pkey PRIMARY KEY (id);


--
-- Name: coach_wish_campaign coach_wish_campaign_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coach_wish_campaign
    ADD CONSTRAINT coach_wish_campaign_pkey PRIMARY KEY (id);


--
-- Name: coach_wish_mutualization coach_wish_mutualization_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coach_wish_mutualization
    ADD CONSTRAINT coach_wish_mutualization_pkey PRIMARY KEY (id);


--
-- Name: coach_wish coach_wish_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coach_wish
    ADD CONSTRAINT coach_wish_pkey PRIMARY KEY (id);


--
-- Name: coach_wish_token coach_wish_token_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coach_wish_token
    ADD CONSTRAINT coach_wish_token_pkey PRIMARY KEY (id);


--
-- Name: competition competition_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.competition
    ADD CONSTRAINT competition_pkey PRIMARY KEY (id);


--
-- Name: conflict_mail_receipt conflict_mail_receipt_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conflict_mail_receipt
    ADD CONSTRAINT conflict_mail_receipt_pkey PRIMARY KEY (id);


--
-- Name: conflict_mailing conflict_mailing_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conflict_mailing
    ADD CONSTRAINT conflict_mailing_pkey PRIMARY KEY (id);


--
-- Name: conflict_resolution conflict_resolution_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conflict_resolution
    ADD CONSTRAINT conflict_resolution_pkey PRIMARY KEY (id);


--
-- Name: constraint_conflict constraint_conflict_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.constraint_conflict
    ADD CONSTRAINT constraint_conflict_pkey PRIMARY KEY (id);


--
-- Name: constraint constraint_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public."constraint"
    ADD CONSTRAINT constraint_pkey PRIMARY KEY (id);


--
-- Name: doctrine_migration_versions doctrine_migration_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doctrine_migration_versions
    ADD CONSTRAINT doctrine_migration_versions_pkey PRIMARY KEY (version);


--
-- Name: email_change_token email_change_token_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_change_token
    ADD CONSTRAINT email_change_token_pkey PRIMARY KEY (id);


--
-- Name: email_verification_token email_verification_token_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_verification_token
    ADD CONSTRAINT email_verification_token_pkey PRIMARY KEY (id);


--
-- Name: fbi_correction fbi_correction_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbi_correction
    ADD CONSTRAINT fbi_correction_pkey PRIMARY KEY (id);


--
-- Name: fbi_ingestion fbi_ingestion_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbi_ingestion
    ADD CONSTRAINT fbi_ingestion_pkey PRIMARY KEY (id);


--
-- Name: feedback feedback_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feedback
    ADD CONSTRAINT feedback_pkey PRIMARY KEY (id);


--
-- Name: ffbb_committee ffbb_committee_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ffbb_committee
    ADD CONSTRAINT ffbb_committee_pkey PRIMARY KEY (id);


--
-- Name: ffbb_league ffbb_league_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ffbb_league
    ADD CONSTRAINT ffbb_league_pkey PRIMARY KEY (id);


--
-- Name: fixture fixture_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fixture
    ADD CONSTRAINT fixture_pkey PRIMARY KEY (id);


--
-- Name: holiday_reminder_log holiday_reminder_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.holiday_reminder_log
    ADD CONSTRAINT holiday_reminder_log_pkey PRIMARY KEY (id);


--
-- Name: implicit_rule_setting implicit_rule_setting_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.implicit_rule_setting
    ADD CONSTRAINT implicit_rule_setting_pkey PRIMARY KEY (id);


--
-- Name: league_match_window league_match_window_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.league_match_window
    ADD CONSTRAINT league_match_window_pkey PRIMARY KEY (id);


--
-- Name: match_constraint match_constraint_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.match_constraint
    ADD CONSTRAINT match_constraint_pkey PRIMARY KEY (id);


--
-- Name: match_module_visit match_module_visit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.match_module_visit
    ADD CONSTRAINT match_module_visit_pkey PRIMARY KEY (id);


--
-- Name: match_placement_run match_placement_run_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.match_placement_run
    ADD CONSTRAINT match_placement_run_pkey PRIMARY KEY (id);


--
-- Name: opponent_directory opponent_directory_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opponent_directory
    ADD CONSTRAINT opponent_directory_pkey PRIMARY KEY (id);


--
-- Name: opponent_venue_link opponent_venue_link_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opponent_venue_link
    ADD CONSTRAINT opponent_venue_link_pkey PRIMARY KEY (id);


--
-- Name: opponent_venue_suggestion opponent_venue_suggestion_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.opponent_venue_suggestion
    ADD CONSTRAINT opponent_venue_suggestion_pkey PRIMARY KEY (id);


--
-- Name: period_reminder_log period_reminder_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.period_reminder_log
    ADD CONSTRAINT period_reminder_log_pkey PRIMARY KEY (id);


--
-- Name: person person_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.person
    ADD CONSTRAINT person_pkey PRIMARY KEY (id);


--
-- Name: place place_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.place
    ADD CONSTRAINT place_pkey PRIMARY KEY (id);


--
-- Name: place_travel_time place_travel_time_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.place_travel_time
    ADD CONSTRAINT place_travel_time_pkey PRIMARY KEY (id);


--
-- Name: plan_constraint plan_constraint_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_constraint
    ADD CONSTRAINT plan_constraint_pkey PRIMARY KEY (id);


--
-- Name: subscription_plan plan_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_plan
    ADD CONSTRAINT plan_pkey PRIMARY KEY (id);


--
-- Name: plan_team plan_team_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_team
    ADD CONSTRAINT plan_team_pkey PRIMARY KEY (id);


--
-- Name: plan_venue plan_venue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_venue
    ADD CONSTRAINT plan_venue_pkey PRIMARY KEY (id);


--
-- Name: priority_tier priority_tier_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.priority_tier
    ADD CONSTRAINT priority_tier_pkey PRIMARY KEY (id);


--
-- Name: public_holiday public_holiday_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.public_holiday
    ADD CONSTRAINT public_holiday_pkey PRIMARY KEY (id);


--
-- Name: release_note release_note_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.release_note
    ADD CONSTRAINT release_note_pkey PRIMARY KEY (id);


--
-- Name: reservation reservation_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation
    ADD CONSTRAINT reservation_pkey PRIMARY KEY (id);


--
-- Name: reset_password_request reset_password_request_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reset_password_request
    ADD CONSTRAINT reset_password_request_pkey PRIMARY KEY (id);


--
-- Name: schedule_diagnostic schedule_diagnostic_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_diagnostic
    ADD CONSTRAINT schedule_diagnostic_pkey PRIMARY KEY (id);


--
-- Name: schedule schedule_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule
    ADD CONSTRAINT schedule_pkey PRIMARY KEY (id);


--
-- Name: schedule_plan schedule_plan_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_plan
    ADD CONSTRAINT schedule_plan_pkey PRIMARY KEY (id);


--
-- Name: schedule_slot_template schedule_slot_template_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_slot_template
    ADD CONSTRAINT schedule_slot_template_pkey PRIMARY KEY (id);


--
-- Name: schedule_state schedule_state_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_state
    ADD CONSTRAINT schedule_state_pkey PRIMARY KEY (id);


--
-- Name: scheduled_deployment scheduled_deployment_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheduled_deployment
    ADD CONSTRAINT scheduled_deployment_pkey PRIMARY KEY (id);


--
-- Name: school_holiday_period school_holiday_period_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_holiday_period
    ADD CONSTRAINT school_holiday_period_pkey PRIMARY KEY (id);


--
-- Name: season season_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.season
    ADD CONSTRAINT season_pkey PRIMARY KEY (id);


--
-- Name: shared_competition_deadline shared_competition_deadline_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shared_competition_deadline
    ADD CONSTRAINT shared_competition_deadline_pkey PRIMARY KEY (id);


--
-- Name: shared_training_block shared_training_block_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shared_training_block
    ADD CONSTRAINT shared_training_block_pkey PRIMARY KEY (id);


--
-- Name: shared_training_block_team shared_training_block_team_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shared_training_block_team
    ADD CONSTRAINT shared_training_block_team_pkey PRIMARY KEY (id);


--
-- Name: solver_metrics solver_metrics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.solver_metrics
    ADD CONSTRAINT solver_metrics_pkey PRIMARY KEY (id);


--
-- Name: sport_category sport_category_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sport_category
    ADD CONSTRAINT sport_category_pkey PRIMARY KEY (id);


--
-- Name: sport sport_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sport
    ADD CONSTRAINT sport_pkey PRIMARY KEY (id);


--
-- Name: subscription_plan_tier subscription_plan_tier_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_plan_tier
    ADD CONSTRAINT subscription_plan_tier_pkey PRIMARY KEY (id);


--
-- Name: super_admin super_admin_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.super_admin
    ADD CONSTRAINT super_admin_pkey PRIMARY KEY (id);


--
-- Name: team_coach team_coach_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_coach
    ADD CONSTRAINT team_coach_pkey PRIMARY KEY (id);


--
-- Name: team_link team_link_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_link
    ADD CONSTRAINT team_link_pkey PRIMARY KEY (id);


--
-- Name: team_match_habit team_match_habit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_match_habit
    ADD CONSTRAINT team_match_habit_pkey PRIMARY KEY (id);


--
-- Name: team team_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team
    ADD CONSTRAINT team_pkey PRIMARY KEY (id);


--
-- Name: transition_reminder_log transition_reminder_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transition_reminder_log
    ADD CONSTRAINT transition_reminder_log_pkey PRIMARY KEY (id);


--
-- Name: subscription_plan_tier uniq_subscription_plan_tier_of_plan; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_plan_tier
    ADD CONSTRAINT uniq_subscription_plan_tier_of_plan UNIQUE (id, plan_id);


--
-- Name: venue_match_window venue_match_window_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_match_window
    ADD CONSTRAINT venue_match_window_pkey PRIMARY KEY (id);


--
-- Name: venue_occupancy venue_occupancy_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_occupancy
    ADD CONSTRAINT venue_occupancy_pkey PRIMARY KEY (id);


--
-- Name: venue venue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue
    ADD CONSTRAINT venue_pkey PRIMARY KEY (id);


--
-- Name: venue_training_slot venue_training_slot_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_training_slot
    ADD CONSTRAINT venue_training_slot_pkey PRIMARY KEY (id);


--
-- Name: venue_travel_rule_setting venue_travel_rule_setting_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_travel_rule_setting
    ADD CONSTRAINT venue_travel_rule_setting_pkey PRIMARY KEY (id);


--
-- Name: idx_7ce748aa76ed395; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_7ce748aa76ed395 ON public.reset_password_request USING btree (user_id);


--
-- Name: idx_admin_audit_actor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_audit_actor ON public.admin_audit_log USING btree (super_admin_id);


--
-- Name: idx_admin_audit_occurred_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_audit_occurred_at ON public.admin_audit_log USING btree (occurred_at);


--
-- Name: idx_admin_job_run_job_started; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_job_run_job_started ON public.admin_job_run USING btree (job_key, started_at DESC);


--
-- Name: idx_admin_job_run_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_job_run_status ON public.admin_job_run USING btree (status);


--
-- Name: idx_audit_log_club_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_log_club_id ON public.audit_log USING btree (club_id);


--
-- Name: idx_audit_log_occurred_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_log_occurred_at ON public.audit_log USING btree (occurred_at);


--
-- Name: idx_calendar_entry_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_calendar_entry_club_season ON public.calendar_entry USING btree (club_id, season_id);


--
-- Name: idx_calendar_entry_parent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_calendar_entry_parent ON public.calendar_entry USING btree (parent_entry_id);


--
-- Name: idx_calendar_entry_window; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_calendar_entry_window ON public.calendar_entry USING btree (club_id, start_date, end_date);


--
-- Name: idx_club_creation_request_ara; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_creation_request_ara ON public.club_creation_request USING btree (ara);


--
-- Name: idx_club_creation_request_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_creation_request_status ON public.club_creation_request USING btree (status);


--
-- Name: idx_club_invitation_club; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_invitation_club ON public.club_invitation USING btree (club_id);


--
-- Name: idx_club_last_activity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_last_activity ON public.club USING btree (last_activity_at) WHERE (last_activity_at IS NOT NULL);


--
-- Name: idx_club_league_window_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_league_window_club_season ON public.club_league_window USING btree (club_id, season_id);


--
-- Name: idx_club_mailbox_message_club_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_mailbox_message_club_created ON public.club_mailbox_message USING btree (club_id, created_at);


--
-- Name: idx_club_subscription_plan; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_subscription_plan ON public.club_subscription USING btree (plan_id);


--
-- Name: idx_club_subscription_tier; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_subscription_tier ON public.club_subscription USING btree (tier_id);


--
-- Name: idx_club_travel_cache_club; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_travel_cache_club ON public.club_travel_cache USING btree (club_id);


--
-- Name: idx_club_user_club; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_user_club ON public.club_user USING btree (club_id);


--
-- Name: idx_club_user_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_club_user_user ON public.club_user USING btree (user_id);


--
-- Name: idx_coach_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coach_club_season ON public.coach USING btree (club_id, season_id);


--
-- Name: idx_coach_parent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coach_parent ON public.coach USING btree (parent_coach_id);


--
-- Name: idx_coach_player_membership_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coach_player_membership_club_season ON public.coach_player_membership USING btree (club_id, season_id);


--
-- Name: idx_coach_player_membership_coach; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coach_player_membership_coach ON public.coach_player_membership USING btree (coach_id);


--
-- Name: idx_coach_player_membership_team; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coach_player_membership_team ON public.coach_player_membership USING btree (team_id);


--
-- Name: idx_coach_wish_entry; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coach_wish_entry ON public.coach_wish USING btree (calendar_entry_id);


--
-- Name: idx_coach_wish_mutualization_entry; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coach_wish_mutualization_entry ON public.coach_wish_mutualization USING btree (calendar_entry_id);


--
-- Name: idx_coach_wish_token_campaign; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_coach_wish_token_campaign ON public.coach_wish_token USING btree (campaign_id);


--
-- Name: idx_competition_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_competition_club_season ON public.competition USING btree (club_id, season_id);


--
-- Name: idx_competition_team; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_competition_team ON public.competition USING btree (team_id);


--
-- Name: idx_conflict_mail_receipt_coach; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_conflict_mail_receipt_coach ON public.conflict_mail_receipt USING btree (club_id, season_id, coach_id);


--
-- Name: idx_conflict_resolution_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_conflict_resolution_club_season ON public.conflict_resolution USING btree (club_id, season_id);


--
-- Name: idx_constraint_calendar_entry; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_constraint_calendar_entry ON public."constraint" USING btree (calendar_entry_id);


--
-- Name: idx_constraint_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_constraint_club_season ON public."constraint" USING btree (club_id, season_id);


--
-- Name: idx_constraint_conflict_schedule; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_constraint_conflict_schedule ON public.constraint_conflict USING btree (schedule_id);


--
-- Name: idx_constraint_parent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_constraint_parent ON public."constraint" USING btree (parent_constraint_id);


--
-- Name: idx_constraint_rule_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_constraint_rule_type ON public."constraint" USING btree (rule_type);


--
-- Name: idx_constraint_scope_family; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_constraint_scope_family ON public."constraint" USING btree (scope, family);


--
-- Name: idx_email_change_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_email_change_user ON public.email_change_token USING btree (user_id);


--
-- Name: idx_email_verification_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_email_verification_user ON public.email_verification_token USING btree (user_id);


--
-- Name: idx_fbi_correction_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fbi_correction_club_season ON public.fbi_correction USING btree (club_id, season_id);


--
-- Name: idx_fbi_ingestion_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fbi_ingestion_club_season ON public.fbi_ingestion USING btree (club_id, season_id);


--
-- Name: idx_fbi_ingestion_deposited; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fbi_ingestion_deposited ON public.fbi_ingestion USING btree (club_id, season_id, deposited_at);


--
-- Name: idx_feedback_club_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_feedback_club_created ON public.feedback USING btree (club_id, created_at);


--
-- Name: idx_feedback_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_feedback_status ON public.feedback USING btree (status);


--
-- Name: idx_fixture_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fixture_club_season ON public.fixture USING btree (club_id, season_id);


--
-- Name: idx_fixture_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fixture_date ON public.fixture USING btree (match_date);


--
-- Name: idx_fixture_team; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fixture_team ON public.fixture USING btree (team_id);


--
-- Name: idx_implicit_rule_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_implicit_rule_club_season ON public.implicit_rule_setting USING btree (club_id, season_id);


--
-- Name: idx_implicit_rule_plan; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_implicit_rule_plan ON public.implicit_rule_setting USING btree (schedule_plan_id);


--
-- Name: idx_league_match_window_league; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_league_match_window_league ON public.league_match_window USING btree (league);


--
-- Name: idx_match_constraint_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_match_constraint_club_season ON public.match_constraint USING btree (club_id, season_id);


--
-- Name: idx_match_constraint_scope; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_match_constraint_scope ON public.match_constraint USING btree (scope, scope_target_id);


--
-- Name: idx_match_placement_run_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_match_placement_run_club_season ON public.match_placement_run USING btree (club_id, season_id);


--
-- Name: idx_match_placement_run_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_match_placement_run_created ON public.match_placement_run USING btree (club_id, created_at);


--
-- Name: idx_opponent_venue_link_club_code; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_opponent_venue_link_club_code ON public.opponent_venue_link USING btree (club_id, opponent_organisme_code);


--
-- Name: idx_person_club; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_person_club ON public.person USING btree (club_id);


--
-- Name: idx_place_club; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_place_club ON public.place USING btree (club_id);


--
-- Name: idx_place_travel_time_place_a; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_place_travel_time_place_a ON public.place_travel_time USING btree (place_a_id);


--
-- Name: idx_place_travel_time_place_b; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_place_travel_time_place_b ON public.place_travel_time USING btree (place_b_id);


--
-- Name: idx_plan_constraint_constraint; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_plan_constraint_constraint ON public.plan_constraint USING btree (constraint_id);


--
-- Name: idx_plan_constraint_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_plan_constraint_season ON public.plan_constraint USING btree (season_id);


--
-- Name: idx_plan_team_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_plan_team_season ON public.plan_team USING btree (season_id);


--
-- Name: idx_plan_team_team; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_plan_team_team ON public.plan_team USING btree (team_id);


--
-- Name: idx_plan_venue_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_plan_venue_season ON public.plan_venue USING btree (season_id);


--
-- Name: idx_plan_venue_venue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_plan_venue_venue ON public.plan_venue USING btree (venue_id);


--
-- Name: idx_release_note_published_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_release_note_published_at ON public.release_note USING btree (published_at);


--
-- Name: idx_reservation_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_reservation_club_season ON public.reservation USING btree (club_id, season_id);


--
-- Name: idx_reservation_schedule_plan; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_reservation_schedule_plan ON public.reservation USING btree (schedule_plan_id);


--
-- Name: idx_reservation_team; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_reservation_team ON public.reservation USING btree (team_id);


--
-- Name: idx_schedule_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_club_season ON public.schedule USING btree (club_id, season_id);


--
-- Name: idx_schedule_diagnostic_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_diagnostic_club_season ON public.schedule_diagnostic USING btree (club_id, season_id);


--
-- Name: idx_schedule_diagnostic_coach; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_diagnostic_coach ON public.schedule_diagnostic USING btree (coach_id);


--
-- Name: idx_schedule_diagnostic_schedule; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_diagnostic_schedule ON public.schedule_diagnostic USING btree (schedule_id);


--
-- Name: idx_schedule_diagnostic_team; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_diagnostic_team ON public.schedule_diagnostic USING btree (team_id);


--
-- Name: idx_schedule_diagnostic_venue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_diagnostic_venue ON public.schedule_diagnostic USING btree (venue_id);


--
-- Name: idx_schedule_plan_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_plan_club_season ON public.schedule_plan USING btree (club_id, season_id);


--
-- Name: idx_schedule_plan_lock_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_plan_lock_source ON public.schedule_plan USING btree (lock_source_schedule_id);


--
-- Name: idx_schedule_slot_template_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_slot_template_club_season ON public.schedule_slot_template USING btree (club_id, season_id);


--
-- Name: idx_schedule_slot_template_coach; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_slot_template_coach ON public.schedule_slot_template USING btree (coach_id);


--
-- Name: idx_schedule_slot_template_schedule; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_slot_template_schedule ON public.schedule_slot_template USING btree (schedule_id);


--
-- Name: idx_schedule_slot_template_shared_block; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_slot_template_shared_block ON public.schedule_slot_template USING btree (shared_training_block_id);


--
-- Name: idx_schedule_slot_template_team; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_slot_template_team ON public.schedule_slot_template USING btree (team_id);


--
-- Name: idx_schedule_slot_template_venue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_slot_template_venue ON public.schedule_slot_template USING btree (venue_id);


--
-- Name: idx_schedule_state_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_state_club_season ON public.schedule_state USING btree (club_id, season_id);


--
-- Name: idx_schedule_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_status ON public.schedule USING btree (status);


--
-- Name: idx_school_holiday_zone_start; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_holiday_zone_start ON public.school_holiday_period USING btree (zone, start_date);


--
-- Name: idx_season_club_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_season_club_status ON public.season USING btree (club_id, status);


--
-- Name: idx_shared_training_block_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_shared_training_block_club_season ON public.shared_training_block USING btree (club_id, season_id);


--
-- Name: idx_shared_training_block_plan; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_shared_training_block_plan ON public.shared_training_block USING btree (schedule_plan_id);


--
-- Name: idx_shared_training_block_team_block; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_shared_training_block_team_block ON public.shared_training_block_team USING btree (block_id);


--
-- Name: idx_shared_training_block_team_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_shared_training_block_team_club_season ON public.shared_training_block_team USING btree (club_id, season_id);


--
-- Name: idx_solver_metrics_club_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_solver_metrics_club_created ON public.solver_metrics USING btree (club_id, created_at);


--
-- Name: idx_solver_metrics_created_brin; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_solver_metrics_created_brin ON public.solver_metrics USING brin (created_at);


--
-- Name: idx_solver_metrics_plan_type_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_solver_metrics_plan_type_created ON public.solver_metrics USING btree (plan_type, created_at);


--
-- Name: idx_solver_metrics_schedule; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_solver_metrics_schedule ON public.solver_metrics USING btree (schedule_id);


--
-- Name: idx_sport_category_club; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sport_category_club ON public.sport_category USING btree (club_id);


--
-- Name: idx_sport_category_sport; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sport_category_sport ON public.sport_category USING btree (sport_id);


--
-- Name: idx_subscription_plan_tier_plan; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscription_plan_tier_plan ON public.subscription_plan_tier USING btree (plan_id);


--
-- Name: idx_team_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_club_season ON public.team USING btree (club_id, season_id);


--
-- Name: idx_team_coach_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_coach_club_season ON public.team_coach USING btree (club_id, season_id);


--
-- Name: idx_team_coach_coach; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_coach_coach ON public.team_coach USING btree (coach_id);


--
-- Name: idx_team_coach_team; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_coach_team ON public.team_coach USING btree (team_id);


--
-- Name: idx_team_forced_venue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_forced_venue ON public.team USING btree (forced_venue_id);


--
-- Name: idx_team_link_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_link_club_season ON public.team_link USING btree (club_id, season_id);


--
-- Name: idx_team_link_team_a; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_link_team_a ON public.team_link USING btree (team_a_id);


--
-- Name: idx_team_link_team_b; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_link_team_b ON public.team_link USING btree (team_b_id);


--
-- Name: idx_team_match_habit_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_match_habit_club_season ON public.team_match_habit USING btree (club_id, season_id);


--
-- Name: idx_team_match_habit_team; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_match_habit_team ON public.team_match_habit USING btree (team_id);


--
-- Name: idx_team_parent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_parent ON public.team USING btree (parent_team_id);


--
-- Name: idx_team_priority_tier; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_priority_tier ON public.team USING btree (priority_tier_id);


--
-- Name: idx_team_sport_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_team_sport_category ON public.team USING btree (sport_category_id);


--
-- Name: idx_venue_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_club_season ON public.venue USING btree (club_id, season_id);


--
-- Name: idx_venue_match_window_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_match_window_club_season ON public.venue_match_window USING btree (club_id, season_id);


--
-- Name: idx_venue_match_window_venue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_match_window_venue ON public.venue_match_window USING btree (venue_id);


--
-- Name: idx_venue_occupancy_club_venue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_occupancy_club_venue ON public.venue_occupancy USING btree (club_id, venue_id);


--
-- Name: idx_venue_occupancy_schedule_plan; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_occupancy_schedule_plan ON public.venue_occupancy USING btree (schedule_plan_id);


--
-- Name: idx_venue_parent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_parent ON public.venue USING btree (parent_venue_id);


--
-- Name: idx_venue_training_slot_club_venue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_training_slot_club_venue ON public.venue_training_slot USING btree (club_id, venue_id);


--
-- Name: idx_venue_travel_rule_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_venue_travel_rule_club_season ON public.venue_travel_rule_setting USING btree (club_id, season_id);


--
-- Name: uniq_admin_job_scheduled_slot; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_admin_job_scheduled_slot ON public.admin_job_run USING btree (job_key, scheduled_for) WHERE ((scheduled_for IS NOT NULL) AND ((status)::text <> 'interrupted'::text));


--
-- Name: uniq_club_creation_request_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_club_creation_request_token ON public.club_creation_request USING btree (token);


--
-- Name: uniq_club_ffbb_club_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_club_ffbb_club_code ON public.club USING btree (ffbb_club_code) WHERE (NOT is_demo);


--
-- Name: uniq_club_invitation_club_email; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_club_invitation_club_email ON public.club_invitation USING btree (club_id, email);


--
-- Name: uniq_club_invitation_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_club_invitation_token ON public.club_invitation USING btree (token_hash);


--
-- Name: uniq_club_league_window; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_club_league_window ON public.club_league_window USING btree (club_id, season_id, category, level, gender, day_of_week, kickoff_min);


--
-- Name: uniq_club_slug; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_club_slug ON public.club USING btree (slug);


--
-- Name: uniq_club_subscription_season; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_club_subscription_season ON public.club_subscription USING btree (club_id, season_year);


--
-- Name: uniq_club_travel_cache_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_club_travel_cache_key ON public.club_travel_cache USING btree (club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon);


--
-- Name: uniq_club_user_membership; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_club_user_membership ON public.club_user USING btree (club_id, user_id);


--
-- Name: uniq_coach_season_person; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_coach_season_person ON public.coach USING btree (season_id, person_id);


--
-- Name: uniq_coach_wish; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_coach_wish ON public.coach_wish USING btree (calendar_entry_id, team_id, week_start);


--
-- Name: uniq_coach_wish_campaign_entry; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_coach_wish_campaign_entry ON public.coach_wish_campaign USING btree (calendar_entry_id);


--
-- Name: uniq_coach_wish_mutualization; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_coach_wish_mutualization ON public.coach_wish_mutualization USING btree (calendar_entry_id, team_id);


--
-- Name: uniq_coach_wish_token_campaign_coach; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_coach_wish_token_campaign_coach ON public.coach_wish_token USING btree (campaign_id, coach_id);


--
-- Name: uniq_coach_wish_token_value; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_coach_wish_token_value ON public.coach_wish_token USING btree (token);


--
-- Name: uniq_competition_team_name; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_competition_team_name ON public.competition USING btree (club_id, season_id, team_id, name);


--
-- Name: uniq_conflict_mail_receipt; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_conflict_mail_receipt ON public.conflict_mail_receipt USING btree (club_id, season_id, fingerprint, coach_id);


--
-- Name: uniq_conflict_mailing_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_conflict_mailing_club_season ON public.conflict_mailing USING btree (club_id, season_id);


--
-- Name: uniq_conflict_resolution_fingerprint; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_conflict_resolution_fingerprint ON public.conflict_resolution USING btree (club_id, season_id, fingerprint);


--
-- Name: uniq_email_change_hashed_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_email_change_hashed_token ON public.email_change_token USING btree (hashed_token);


--
-- Name: uniq_email_verification_hashed_token; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_email_verification_hashed_token ON public.email_verification_token USING btree (hashed_token);


--
-- Name: uniq_fbi_correction_open; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_fbi_correction_open ON public.fbi_correction USING btree (club_id, season_id, fixture_id, field) WHERE (closed_at IS NULL);


--
-- Name: uniq_ffbb_committee_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_ffbb_committee_code ON public.ffbb_committee USING btree (code);


--
-- Name: uniq_ffbb_league_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_ffbb_league_code ON public.ffbb_league USING btree (code);


--
-- Name: uniq_fixture_external_ref; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_fixture_external_ref ON public.fixture USING btree (club_id, season_id, team_id, external_division, external_ref) WHERE (external_ref IS NOT NULL);


--
-- Name: uniq_fixture_ffbb_rencontre; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_fixture_ffbb_rencontre ON public.fixture USING btree (club_id, season_id, team_id, ffbb_rencontre_id) WHERE (ffbb_rencontre_id IS NOT NULL);


--
-- Name: uniq_holiday_reminder; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_holiday_reminder ON public.holiday_reminder_log USING btree (season_id, school_holiday_id, threshold);


--
-- Name: uniq_implicit_rule_club_season_plan_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_implicit_rule_club_season_plan_key ON public.implicit_rule_setting USING btree (club_id, season_id, schedule_plan_id, rule_key) NULLS NOT DISTINCT;


--
-- Name: uniq_league_match_window; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_league_match_window ON public.league_match_window USING btree (league, category, level, gender, day_of_week, kickoff_min) NULLS NOT DISTINCT;


--
-- Name: uniq_match_module_visit_scope; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_match_module_visit_scope ON public.match_module_visit USING btree (club_id, season_id, user_id);


--
-- Name: uniq_opponent_directory_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_opponent_directory_code ON public.opponent_directory USING btree (ffbb_organisme_code);


--
-- Name: uniq_opponent_venue_link; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_opponent_venue_link ON public.opponent_venue_link USING btree (club_id, opponent_organisme_code, fbi_label_norm);


--
-- Name: uniq_opponent_venue_suggestion_label; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_opponent_venue_suggestion_label ON public.opponent_venue_suggestion USING btree (ffbb_organisme_code, lower((venue_label)::text)) WHERE (venue_external_ref IS NULL);


--
-- Name: uniq_opponent_venue_suggestion_ref; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_opponent_venue_suggestion_ref ON public.opponent_venue_suggestion USING btree (ffbb_organisme_code, venue_external_ref) WHERE (venue_external_ref IS NOT NULL);


--
-- Name: uniq_period_reminder; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_period_reminder ON public.period_reminder_log USING btree (calendar_entry_id, threshold);


--
-- Name: uniq_person_id_club; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_person_id_club ON public.person USING btree (id, club_id);


--
-- Name: uniq_place_id_club; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_place_id_club ON public.place USING btree (id, club_id);


--
-- Name: uniq_place_travel_time_couple; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_place_travel_time_couple ON public.place_travel_time USING btree (club_id, place_a_id, place_b_id);


--
-- Name: uniq_plan_constraint; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_plan_constraint ON public.plan_constraint USING btree (schedule_plan_id, constraint_id);


--
-- Name: uniq_plan_team; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_plan_team ON public.plan_team USING btree (schedule_plan_id, team_id);


--
-- Name: uniq_plan_venue; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_plan_venue ON public.plan_venue USING btree (schedule_plan_id, venue_id);


--
-- Name: uniq_public_holiday_zone_date; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_public_holiday_zone_date ON public.public_holiday USING btree (zone, holiday_date);


--
-- Name: uniq_reservation_case_team; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_reservation_case_team ON public.reservation USING btree (club_id, season_id, schedule_plan_id, venue_id, day_of_week, start_time, team_id) NULLS NOT DISTINCT;


--
-- Name: uniq_schedule_plan_calendar_entry; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_schedule_plan_calendar_entry ON public.schedule_plan USING btree (calendar_entry_id) WHERE (calendar_entry_id IS NOT NULL);


--
-- Name: uniq_schedule_plan_id_club; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_schedule_plan_id_club ON public.schedule_plan USING btree (id, club_id);


--
-- Name: uniq_schedule_plan_season_base; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_schedule_plan_season_base ON public.schedule_plan USING btree (season_id) WHERE ((type)::text = 'SEASON'::text);


--
-- Name: uniq_schedule_plan_version; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_schedule_plan_version ON public.schedule USING btree (schedule_plan_id, version_number) WHERE ((schedule_plan_id IS NOT NULL) AND (version_number IS NOT NULL));


--
-- Name: uniq_schedule_slot_template_case; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_schedule_slot_template_case ON public.schedule_slot_template USING btree (schedule_id, team_id, venue_id, day_of_week, start_time);


--
-- Name: uniq_schedule_state_schedule; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_schedule_state_schedule ON public.schedule_state USING btree (schedule_id);


--
-- Name: uniq_scheduled_deployment_active; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_scheduled_deployment_active ON public.scheduled_deployment USING btree ((((status)::text = ANY ((ARRAY['scheduled'::character varying, 'dispatched'::character varying])::text[])))) WHERE ((status)::text = ANY ((ARRAY['scheduled'::character varying, 'dispatched'::character varying])::text[]));


--
-- Name: uniq_school_holiday_zone_type_year; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_school_holiday_zone_type_year ON public.school_holiday_period USING btree (zone, holiday_type, school_year);


--
-- Name: uniq_shared_competition_deadline_ffbb; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_shared_competition_deadline_ffbb ON public.shared_competition_deadline USING btree (ffbb_competition_id);


--
-- Name: uniq_shared_training_block_team; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_shared_training_block_team ON public.shared_training_block_team USING btree (block_id, team_id);


--
-- Name: uniq_sport_slug; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_sport_slug ON public.sport USING btree (slug);


--
-- Name: uniq_subscription_plan_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_subscription_plan_code ON public.subscription_plan USING btree (code);


--
-- Name: uniq_subscription_plan_tier_code; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_subscription_plan_tier_code ON public.subscription_plan_tier USING btree (code);


--
-- Name: uniq_super_admin_email; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_super_admin_email ON public.super_admin USING btree (lower((email)::text));


--
-- Name: uniq_team_coach_role; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_team_coach_role ON public.team_coach USING btree (team_id, coach_id, role);


--
-- Name: uniq_team_link_couple; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_team_link_couple ON public.team_link USING btree (club_id, season_id, team_a_id, team_b_id);


--
-- Name: uniq_team_match_habit_team; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_team_match_habit_team ON public.team_match_habit USING btree (club_id, season_id, team_id);


--
-- Name: uniq_transition_reminder; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_transition_reminder ON public.transition_reminder_log USING btree (season_id, threshold);


--
-- Name: uniq_user_email; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_user_email ON public.app_user USING btree (email);


--
-- Name: uniq_user_pending_email; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_user_pending_email ON public.app_user USING btree (pending_email);


--
-- Name: uniq_venue_season_place; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_venue_season_place ON public.venue USING btree (season_id, place_id);


--
-- Name: uniq_venue_travel_rule_club_season; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_venue_travel_rule_club_season ON public.venue_travel_rule_setting USING btree (club_id, season_id);


--
-- Name: club_subscription trg_club_subscription_tier_required; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_club_subscription_tier_required BEFORE INSERT OR UPDATE OF plan_id, tier_id ON public.club_subscription FOR EACH ROW EXECUTE FUNCTION public.club_subscription_tier_required();


--
-- Name: reset_password_request fk_7ce748aa76ed395; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reset_password_request
    ADD CONSTRAINT fk_7ce748aa76ed395 FOREIGN KEY (user_id) REFERENCES public.app_user(id) ON DELETE CASCADE;


--
-- Name: club_invitation fk_club_invitation_club; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_invitation
    ADD CONSTRAINT fk_club_invitation_club FOREIGN KEY (club_id) REFERENCES public.club(id) ON DELETE CASCADE;


--
-- Name: club_mailbox_message fk_club_mailbox_message_club; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_mailbox_message
    ADD CONSTRAINT fk_club_mailbox_message_club FOREIGN KEY (club_id) REFERENCES public.club(id) ON DELETE CASCADE;


--
-- Name: club_subscription fk_club_subscription_club; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_subscription
    ADD CONSTRAINT fk_club_subscription_club FOREIGN KEY (club_id) REFERENCES public.club(id) ON DELETE CASCADE;


--
-- Name: club_subscription fk_club_subscription_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_subscription
    ADD CONSTRAINT fk_club_subscription_plan FOREIGN KEY (plan_id) REFERENCES public.subscription_plan(id) ON DELETE RESTRICT;


--
-- Name: club_subscription fk_club_subscription_tier; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_subscription
    ADD CONSTRAINT fk_club_subscription_tier FOREIGN KEY (tier_id) REFERENCES public.subscription_plan_tier(id) ON DELETE RESTRICT;


--
-- Name: club_subscription fk_club_subscription_tier_of_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.club_subscription
    ADD CONSTRAINT fk_club_subscription_tier_of_plan FOREIGN KEY (tier_id, plan_id) REFERENCES public.subscription_plan_tier(id, plan_id) ON DELETE RESTRICT;


--
-- Name: coach fk_coach_person_club; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coach
    ADD CONSTRAINT fk_coach_person_club FOREIGN KEY (person_id, club_id) REFERENCES public.person(id, club_id) ON DELETE RESTRICT;


--
-- Name: coach_wish_token fk_coach_wish_token_campaign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coach_wish_token
    ADD CONSTRAINT fk_coach_wish_token_campaign FOREIGN KEY (campaign_id) REFERENCES public.coach_wish_campaign(id) ON DELETE CASCADE;


--
-- Name: coach_wish_token fk_coach_wish_token_coach; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coach_wish_token
    ADD CONSTRAINT fk_coach_wish_token_coach FOREIGN KEY (coach_id) REFERENCES public.coach(id) ON DELETE CASCADE;


--
-- Name: conflict_mail_receipt fk_conflict_mail_receipt_coach; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conflict_mail_receipt
    ADD CONSTRAINT fk_conflict_mail_receipt_coach FOREIGN KEY (coach_id) REFERENCES public.coach(id) ON DELETE CASCADE;


--
-- Name: email_change_token fk_email_change_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_change_token
    ADD CONSTRAINT fk_email_change_user FOREIGN KEY (user_id) REFERENCES public.app_user(id) ON DELETE CASCADE;


--
-- Name: email_verification_token fk_email_verification_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_verification_token
    ADD CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES public.app_user(id) ON DELETE CASCADE;


--
-- Name: implicit_rule_setting fk_implicit_rule_setting_schedule_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.implicit_rule_setting
    ADD CONSTRAINT fk_implicit_rule_setting_schedule_plan FOREIGN KEY (schedule_plan_id) REFERENCES public.schedule_plan(id) ON DELETE CASCADE;


--
-- Name: place_travel_time fk_place_travel_time_place_a_club; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.place_travel_time
    ADD CONSTRAINT fk_place_travel_time_place_a_club FOREIGN KEY (place_a_id, club_id) REFERENCES public.place(id, club_id) ON DELETE CASCADE;


--
-- Name: place_travel_time fk_place_travel_time_place_b_club; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.place_travel_time
    ADD CONSTRAINT fk_place_travel_time_place_b_club FOREIGN KEY (place_b_id, club_id) REFERENCES public.place(id, club_id) ON DELETE CASCADE;


--
-- Name: plan_constraint fk_plan_constraint_schedule_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_constraint
    ADD CONSTRAINT fk_plan_constraint_schedule_plan FOREIGN KEY (schedule_plan_id, club_id) REFERENCES public.schedule_plan(id, club_id) ON DELETE CASCADE;


--
-- Name: plan_team fk_plan_team_schedule_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_team
    ADD CONSTRAINT fk_plan_team_schedule_plan FOREIGN KEY (schedule_plan_id, club_id) REFERENCES public.schedule_plan(id, club_id) ON DELETE CASCADE;


--
-- Name: plan_venue fk_plan_venue_schedule_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plan_venue
    ADD CONSTRAINT fk_plan_venue_schedule_plan FOREIGN KEY (schedule_plan_id, club_id) REFERENCES public.schedule_plan(id, club_id) ON DELETE CASCADE;


--
-- Name: reservation fk_reservation_schedule_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation
    ADD CONSTRAINT fk_reservation_schedule_plan FOREIGN KEY (schedule_plan_id) REFERENCES public.schedule_plan(id) ON DELETE CASCADE;


--
-- Name: schedule_plan fk_schedule_plan_lock_source; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_plan
    ADD CONSTRAINT fk_schedule_plan_lock_source FOREIGN KEY (lock_source_schedule_id) REFERENCES public.schedule(id) ON DELETE SET NULL;


--
-- Name: schedule_slot_template fk_schedule_slot_template_shared_block; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_slot_template
    ADD CONSTRAINT fk_schedule_slot_template_shared_block FOREIGN KEY (shared_training_block_id) REFERENCES public.shared_training_block(id) ON DELETE CASCADE;


--
-- Name: schedule_state fk_schedule_state_schedule; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_state
    ADD CONSTRAINT fk_schedule_state_schedule FOREIGN KEY (schedule_id) REFERENCES public.schedule(id) ON DELETE CASCADE;


--
-- Name: shared_training_block fk_shared_training_block_schedule_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shared_training_block
    ADD CONSTRAINT fk_shared_training_block_schedule_plan FOREIGN KEY (schedule_plan_id) REFERENCES public.schedule_plan(id) ON DELETE CASCADE;


--
-- Name: shared_training_block_team fk_shared_training_block_team_schedule_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shared_training_block_team
    ADD CONSTRAINT fk_shared_training_block_team_schedule_plan FOREIGN KEY (schedule_plan_id) REFERENCES public.schedule_plan(id) ON DELETE CASCADE;


--
-- Name: subscription_plan_tier fk_subscription_plan_tier_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_plan_tier
    ADD CONSTRAINT fk_subscription_plan_tier_plan FOREIGN KEY (plan_id) REFERENCES public.subscription_plan(id) ON DELETE RESTRICT;


--
-- Name: venue_occupancy fk_venue_occupancy_schedule_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_occupancy
    ADD CONSTRAINT fk_venue_occupancy_schedule_plan FOREIGN KEY (schedule_plan_id) REFERENCES public.schedule_plan(id) ON DELETE CASCADE;


--
-- Name: venue fk_venue_place_club; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue
    ADD CONSTRAINT fk_venue_place_club FOREIGN KEY (place_id, club_id) REFERENCES public.place(id, club_id) ON DELETE RESTRICT;


--
-- Name: venue_training_slot fk_venue_training_slot_schedule_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.venue_training_slot
    ADD CONSTRAINT fk_venue_training_slot_schedule_plan FOREIGN KEY (schedule_plan_id) REFERENCES public.schedule_plan(id) ON DELETE CASCADE;


--
-- Name: audit_log admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.audit_log TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: calendar_entry admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.calendar_entry TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: club_invitation admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.club_invitation TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: club_league_window admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.club_league_window TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: club_mailbox_message admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.club_mailbox_message TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: club_subscription admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.club_subscription TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: club_travel_cache admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.club_travel_cache TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: club_user admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.club_user TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: coach admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.coach TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: coach_player_membership admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.coach_player_membership TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: coach_wish admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.coach_wish TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: coach_wish_campaign admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.coach_wish_campaign TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: coach_wish_mutualization admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.coach_wish_mutualization TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: coach_wish_token admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.coach_wish_token TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: competition admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.competition TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: conflict_mail_receipt admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.conflict_mail_receipt TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: conflict_mailing admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.conflict_mailing TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: conflict_resolution admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.conflict_resolution TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: constraint admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public."constraint" TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: fbi_correction admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.fbi_correction TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: fbi_ingestion admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.fbi_ingestion TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: feedback admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.feedback TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: fixture admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.fixture TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: implicit_rule_setting admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.implicit_rule_setting TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: match_constraint admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.match_constraint TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: match_module_visit admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.match_module_visit TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: match_placement_run admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.match_placement_run TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: opponent_venue_link admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.opponent_venue_link TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: person admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.person TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: place admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.place TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: place_travel_time admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.place_travel_time TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: plan_constraint admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.plan_constraint TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: plan_team admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.plan_team TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: plan_venue admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.plan_venue TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: reservation admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.reservation TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: schedule admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.schedule TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: schedule_diagnostic admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.schedule_diagnostic TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: schedule_plan admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.schedule_plan TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: schedule_slot_template admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.schedule_slot_template TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: schedule_state admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.schedule_state TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: season admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.season TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: shared_training_block admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.shared_training_block TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: shared_training_block_team admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.shared_training_block_team TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: solver_metrics admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.solver_metrics TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: sport_category admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.sport_category TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: team admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.team TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: team_coach admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.team_coach TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: team_link admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.team_link TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: team_match_habit admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.team_match_habit TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: venue admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.venue TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: venue_match_window admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.venue_match_window TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: venue_occupancy admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.venue_occupancy TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: venue_training_slot admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.venue_training_slot TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: venue_travel_rule_setting admin_all; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY admin_all ON public.venue_travel_rule_setting TO amateo_owner USING (true) WITH CHECK (true);


--
-- Name: audit_log; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.audit_log ENABLE ROW LEVEL SECURITY;

--
-- Name: audit_log audit_log_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY audit_log_insert ON public.audit_log FOR INSERT TO amateo_app WITH CHECK (((club_id IS NULL) OR (club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)));


--
-- Name: audit_log audit_log_select; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY audit_log_select ON public.audit_log FOR SELECT TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: calendar_entry; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.calendar_entry ENABLE ROW LEVEL SECURITY;

--
-- Name: club_invitation; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.club_invitation ENABLE ROW LEVEL SECURITY;

--
-- Name: club_invitation club_invitation_read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY club_invitation_read ON public.club_invitation FOR SELECT TO amateo_app USING (((NULLIF(current_setting('app.club_id'::text, true), ''::text) IS NULL) OR (club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)));


--
-- Name: club_league_window; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.club_league_window ENABLE ROW LEVEL SECURITY;

--
-- Name: club_mailbox_message; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.club_mailbox_message ENABLE ROW LEVEL SECURITY;

--
-- Name: club_subscription; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.club_subscription ENABLE ROW LEVEL SECURITY;

--
-- Name: club_travel_cache; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.club_travel_cache ENABLE ROW LEVEL SECURITY;

--
-- Name: club_user; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.club_user ENABLE ROW LEVEL SECURITY;

--
-- Name: club_user club_user_read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY club_user_read ON public.club_user FOR SELECT TO amateo_app USING (((NULLIF(current_setting('app.club_id'::text, true), ''::text) IS NULL) OR (club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)));


--
-- Name: coach; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.coach ENABLE ROW LEVEL SECURITY;

--
-- Name: coach_player_membership; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.coach_player_membership ENABLE ROW LEVEL SECURITY;

--
-- Name: coach_wish; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.coach_wish ENABLE ROW LEVEL SECURITY;

--
-- Name: coach_wish_campaign; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.coach_wish_campaign ENABLE ROW LEVEL SECURITY;

--
-- Name: coach_wish_mutualization; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.coach_wish_mutualization ENABLE ROW LEVEL SECURITY;

--
-- Name: coach_wish_token; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.coach_wish_token ENABLE ROW LEVEL SECURITY;

--
-- Name: coach_wish_token coach_wish_token_read; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY coach_wish_token_read ON public.coach_wish_token FOR SELECT TO amateo_app USING (((NULLIF(current_setting('app.club_id'::text, true), ''::text) IS NULL) OR (club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)));


--
-- Name: competition; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.competition ENABLE ROW LEVEL SECURITY;

--
-- Name: conflict_mail_receipt; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.conflict_mail_receipt ENABLE ROW LEVEL SECURITY;

--
-- Name: conflict_mailing; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.conflict_mailing ENABLE ROW LEVEL SECURITY;

--
-- Name: conflict_resolution; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.conflict_resolution ENABLE ROW LEVEL SECURITY;

--
-- Name: constraint; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public."constraint" ENABLE ROW LEVEL SECURITY;

--
-- Name: fbi_correction; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fbi_correction ENABLE ROW LEVEL SECURITY;

--
-- Name: fbi_ingestion; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fbi_ingestion ENABLE ROW LEVEL SECURITY;

--
-- Name: feedback; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.feedback ENABLE ROW LEVEL SECURITY;

--
-- Name: fixture; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.fixture ENABLE ROW LEVEL SECURITY;

--
-- Name: implicit_rule_setting; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.implicit_rule_setting ENABLE ROW LEVEL SECURITY;

--
-- Name: match_constraint; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.match_constraint ENABLE ROW LEVEL SECURITY;

--
-- Name: match_module_visit; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.match_module_visit ENABLE ROW LEVEL SECURITY;

--
-- Name: match_placement_run; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.match_placement_run ENABLE ROW LEVEL SECURITY;

--
-- Name: opponent_venue_link; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.opponent_venue_link ENABLE ROW LEVEL SECURITY;

--
-- Name: person; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.person ENABLE ROW LEVEL SECURITY;

--
-- Name: place; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.place ENABLE ROW LEVEL SECURITY;

--
-- Name: place_travel_time; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.place_travel_time ENABLE ROW LEVEL SECURITY;

--
-- Name: plan_constraint; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.plan_constraint ENABLE ROW LEVEL SECURITY;

--
-- Name: plan_team; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.plan_team ENABLE ROW LEVEL SECURITY;

--
-- Name: plan_venue; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.plan_venue ENABLE ROW LEVEL SECURITY;

--
-- Name: audit_log readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.audit_log FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: calendar_entry readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.calendar_entry FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_invitation readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.club_invitation FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_league_window readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.club_league_window FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_mailbox_message readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.club_mailbox_message FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_subscription readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.club_subscription FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_travel_cache readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.club_travel_cache FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_user readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.club_user FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.coach FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_player_membership readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.coach_player_membership FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.coach_wish FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish_campaign readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.coach_wish_campaign FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish_mutualization readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.coach_wish_mutualization FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish_token readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.coach_wish_token FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: competition readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.competition FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: conflict_mail_receipt readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.conflict_mail_receipt FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: conflict_mailing readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.conflict_mailing FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: conflict_resolution readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.conflict_resolution FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: constraint readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public."constraint" FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: fbi_correction readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.fbi_correction FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: fbi_ingestion readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.fbi_ingestion FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: feedback readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.feedback FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: fixture readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.fixture FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: implicit_rule_setting readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.implicit_rule_setting FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: match_constraint readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.match_constraint FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: match_module_visit readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.match_module_visit FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: match_placement_run readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.match_placement_run FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: opponent_venue_link readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.opponent_venue_link FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: person readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.person FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: place readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.place FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: place_travel_time readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.place_travel_time FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: plan_constraint readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.plan_constraint FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: plan_team readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.plan_team FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: plan_venue readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.plan_venue FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: reservation readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.reservation FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.schedule FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule_diagnostic readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.schedule_diagnostic FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule_plan readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.schedule_plan FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule_slot_template readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.schedule_slot_template FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule_state readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.schedule_state FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: season readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.season FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: shared_training_block readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.shared_training_block FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: shared_training_block_team readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.shared_training_block_team FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: solver_metrics readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.solver_metrics FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: sport_category readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.sport_category FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: team readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.team FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: team_coach readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.team_coach FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: team_link readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.team_link FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: team_match_habit readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.team_match_habit FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.venue FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue_match_window readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.venue_match_window FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue_occupancy readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.venue_occupancy FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue_training_slot readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.venue_training_slot FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue_travel_rule_setting readonly_tenant; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY readonly_tenant ON public.venue_travel_rule_setting FOR SELECT TO amateo_read USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: reservation; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.reservation ENABLE ROW LEVEL SECURITY;

--
-- Name: schedule; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.schedule ENABLE ROW LEVEL SECURITY;

--
-- Name: schedule_diagnostic; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.schedule_diagnostic ENABLE ROW LEVEL SECURITY;

--
-- Name: schedule_plan; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.schedule_plan ENABLE ROW LEVEL SECURITY;

--
-- Name: schedule_slot_template; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.schedule_slot_template ENABLE ROW LEVEL SECURITY;

--
-- Name: schedule_state; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.schedule_state ENABLE ROW LEVEL SECURITY;

--
-- Name: season; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.season ENABLE ROW LEVEL SECURITY;

--
-- Name: shared_training_block; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.shared_training_block ENABLE ROW LEVEL SECURITY;

--
-- Name: shared_training_block_team; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.shared_training_block_team ENABLE ROW LEVEL SECURITY;

--
-- Name: solver_metrics; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.solver_metrics ENABLE ROW LEVEL SECURITY;

--
-- Name: sport_category; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.sport_category ENABLE ROW LEVEL SECURITY;

--
-- Name: team; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.team ENABLE ROW LEVEL SECURITY;

--
-- Name: team_coach; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.team_coach ENABLE ROW LEVEL SECURITY;

--
-- Name: team_link; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.team_link ENABLE ROW LEVEL SECURITY;

--
-- Name: team_match_habit; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.team_match_habit ENABLE ROW LEVEL SECURITY;

--
-- Name: calendar_entry tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.calendar_entry TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_league_window tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.club_league_window TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_mailbox_message tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.club_mailbox_message TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_subscription tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.club_subscription TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_travel_cache tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.club_travel_cache TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.coach TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_player_membership tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.coach_player_membership TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.coach_wish TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish_campaign tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.coach_wish_campaign TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish_mutualization tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.coach_wish_mutualization TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: competition tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.competition TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: conflict_mail_receipt tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.conflict_mail_receipt TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: conflict_mailing tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.conflict_mailing TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: conflict_resolution tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.conflict_resolution TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: constraint tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public."constraint" TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: fbi_correction tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.fbi_correction TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: fbi_ingestion tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.fbi_ingestion TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: feedback tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.feedback TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: fixture tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.fixture TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: implicit_rule_setting tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.implicit_rule_setting TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: match_constraint tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.match_constraint TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: match_module_visit tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.match_module_visit TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: match_placement_run tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.match_placement_run TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: opponent_venue_link tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.opponent_venue_link TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: person tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.person TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: place tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.place TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: place_travel_time tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.place_travel_time TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: plan_constraint tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.plan_constraint TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: plan_team tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.plan_team TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: plan_venue tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.plan_venue TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: reservation tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.reservation TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.schedule TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule_diagnostic tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.schedule_diagnostic TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule_plan tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.schedule_plan TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule_slot_template tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.schedule_slot_template TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: schedule_state tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.schedule_state TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: season tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.season TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: shared_training_block tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.shared_training_block TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: shared_training_block_team tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.shared_training_block_team TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: solver_metrics tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.solver_metrics TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: sport_category tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.sport_category TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: team tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.team TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: team_coach tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.team_coach TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: team_link tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.team_link TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: team_match_habit tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.team_match_habit TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.venue TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue_match_window tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.venue_match_window TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue_occupancy tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.venue_occupancy TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue_training_slot tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.venue_training_slot TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue_travel_rule_setting tenant_isolation; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation ON public.venue_travel_rule_setting TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_invitation tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.club_invitation FOR DELETE TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_user tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.club_user FOR DELETE TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish_token tenant_isolation_delete; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_delete ON public.coach_wish_token FOR DELETE TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_invitation tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.club_invitation FOR INSERT TO amateo_app WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_user tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.club_user FOR INSERT TO amateo_app WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish_token tenant_isolation_insert; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_insert ON public.coach_wish_token FOR INSERT TO amateo_app WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_invitation tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.club_invitation FOR UPDATE TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: club_user tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.club_user FOR UPDATE TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: coach_wish_token tenant_isolation_update; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tenant_isolation_update ON public.coach_wish_token FOR UPDATE TO amateo_app USING ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid)) WITH CHECK ((club_id = (NULLIF(current_setting('app.club_id'::text, true), ''::text))::uuid));


--
-- Name: venue; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.venue ENABLE ROW LEVEL SECURITY;

--
-- Name: venue_match_window; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.venue_match_window ENABLE ROW LEVEL SECURITY;

--
-- Name: venue_occupancy; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.venue_occupancy ENABLE ROW LEVEL SECURITY;

--
-- Name: venue_training_slot; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.venue_training_slot ENABLE ROW LEVEL SECURITY;

--
-- Name: venue_travel_rule_setting; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.venue_travel_rule_setting ENABLE ROW LEVEL SECURITY;

--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: -
--

GRANT USAGE ON SCHEMA public TO amateo_app;
GRANT USAGE ON SCHEMA public TO amateo_read;


--
-- Name: FUNCTION league_window_suggestions(p_requesting_club uuid); Type: ACL; Schema: public; Owner: -
--

REVOKE ALL ON FUNCTION public.league_window_suggestions(p_requesting_club uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.league_window_suggestions(p_requesting_club uuid) TO amateo_app;


--
-- Name: TABLE app_user; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.app_user TO amateo_app;


--
-- Name: COLUMN app_user.id; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(id) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.version; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(version) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.created_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(created_at) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.updated_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(updated_at) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.email; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(email) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.first_name; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(first_name) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.last_name; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(last_name) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.email_verified_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(email_verified_at) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.anonymized_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(anonymized_at) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.last_login_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(last_login_at) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.inactivity_warned_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(inactivity_warned_at) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.terms_accepted_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(terms_accepted_at) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.terms_version; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(terms_version) ON TABLE public.app_user TO amateo_read;


--
-- Name: COLUMN app_user.release_notes_seen_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(release_notes_seen_at) ON TABLE public.app_user TO amateo_read;


--
-- Name: TABLE audit_log; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.audit_log TO amateo_app;
GRANT SELECT ON TABLE public.audit_log TO amateo_read;


--
-- Name: TABLE calendar_entry; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.calendar_entry TO amateo_app;
GRANT SELECT ON TABLE public.calendar_entry TO amateo_read;


--
-- Name: TABLE club; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.club TO amateo_app;
GRANT SELECT ON TABLE public.club TO amateo_read;


--
-- Name: TABLE club_creation_request; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.club_creation_request TO amateo_app;


--
-- Name: TABLE club_invitation; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.club_invitation TO amateo_app;


--
-- Name: COLUMN club_invitation.id; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(id) ON TABLE public.club_invitation TO amateo_read;


--
-- Name: COLUMN club_invitation.club_id; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(club_id) ON TABLE public.club_invitation TO amateo_read;


--
-- Name: COLUMN club_invitation.email; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(email) ON TABLE public.club_invitation TO amateo_read;


--
-- Name: COLUMN club_invitation.role; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(role) ON TABLE public.club_invitation TO amateo_read;


--
-- Name: COLUMN club_invitation.expires_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(expires_at) ON TABLE public.club_invitation TO amateo_read;


--
-- Name: COLUMN club_invitation.created_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(created_at) ON TABLE public.club_invitation TO amateo_read;


--
-- Name: COLUMN club_invitation.invited_by_user_id; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(invited_by_user_id) ON TABLE public.club_invitation TO amateo_read;


--
-- Name: TABLE club_league_window; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.club_league_window TO amateo_app;
GRANT SELECT ON TABLE public.club_league_window TO amateo_read;


--
-- Name: TABLE club_mailbox_message; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.club_mailbox_message TO amateo_app;
GRANT SELECT ON TABLE public.club_mailbox_message TO amateo_read;


--
-- Name: TABLE club_subscription; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,DELETE ON TABLE public.club_subscription TO amateo_app;
GRANT SELECT ON TABLE public.club_subscription TO amateo_read;


--
-- Name: TABLE club_travel_cache; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.club_travel_cache TO amateo_app;
GRANT SELECT ON TABLE public.club_travel_cache TO amateo_read;


--
-- Name: TABLE club_user; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.club_user TO amateo_app;
GRANT SELECT ON TABLE public.club_user TO amateo_read;


--
-- Name: TABLE coach; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.coach TO amateo_app;
GRANT SELECT ON TABLE public.coach TO amateo_read;


--
-- Name: TABLE coach_player_membership; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.coach_player_membership TO amateo_app;
GRANT SELECT ON TABLE public.coach_player_membership TO amateo_read;


--
-- Name: TABLE coach_wish; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.coach_wish TO amateo_app;
GRANT SELECT ON TABLE public.coach_wish TO amateo_read;


--
-- Name: TABLE coach_wish_campaign; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.coach_wish_campaign TO amateo_app;
GRANT SELECT ON TABLE public.coach_wish_campaign TO amateo_read;


--
-- Name: TABLE coach_wish_mutualization; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.coach_wish_mutualization TO amateo_app;
GRANT SELECT ON TABLE public.coach_wish_mutualization TO amateo_read;


--
-- Name: TABLE coach_wish_token; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.coach_wish_token TO amateo_app;


--
-- Name: COLUMN coach_wish_token.id; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(id) ON TABLE public.coach_wish_token TO amateo_read;


--
-- Name: COLUMN coach_wish_token.campaign_id; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(campaign_id) ON TABLE public.coach_wish_token TO amateo_read;


--
-- Name: COLUMN coach_wish_token.coach_id; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(coach_id) ON TABLE public.coach_wish_token TO amateo_read;


--
-- Name: COLUMN coach_wish_token.club_id; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(club_id) ON TABLE public.coach_wish_token TO amateo_read;


--
-- Name: COLUMN coach_wish_token.responded_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(responded_at) ON TABLE public.coach_wish_token TO amateo_read;


--
-- Name: COLUMN coach_wish_token.created_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(created_at) ON TABLE public.coach_wish_token TO amateo_read;


--
-- Name: COLUMN coach_wish_token.sent_at; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT(sent_at) ON TABLE public.coach_wish_token TO amateo_read;


--
-- Name: TABLE competition; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.competition TO amateo_app;
GRANT SELECT ON TABLE public.competition TO amateo_read;


--
-- Name: TABLE conflict_mail_receipt; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.conflict_mail_receipt TO amateo_app;
GRANT SELECT ON TABLE public.conflict_mail_receipt TO amateo_read;


--
-- Name: TABLE conflict_mailing; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.conflict_mailing TO amateo_app;
GRANT SELECT ON TABLE public.conflict_mailing TO amateo_read;


--
-- Name: TABLE conflict_resolution; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.conflict_resolution TO amateo_app;
GRANT SELECT ON TABLE public.conflict_resolution TO amateo_read;


--
-- Name: TABLE "constraint"; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public."constraint" TO amateo_app;
GRANT SELECT ON TABLE public."constraint" TO amateo_read;


--
-- Name: TABLE constraint_conflict; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.constraint_conflict TO amateo_app;


--
-- Name: TABLE doctrine_migration_versions; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.doctrine_migration_versions TO amateo_app;


--
-- Name: TABLE email_change_token; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.email_change_token TO amateo_app;


--
-- Name: SEQUENCE email_change_token_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,USAGE ON SEQUENCE public.email_change_token_id_seq TO amateo_app;


--
-- Name: TABLE email_verification_token; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.email_verification_token TO amateo_app;


--
-- Name: SEQUENCE email_verification_token_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,USAGE ON SEQUENCE public.email_verification_token_id_seq TO amateo_app;


--
-- Name: TABLE fbi_correction; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.fbi_correction TO amateo_app;
GRANT SELECT ON TABLE public.fbi_correction TO amateo_read;


--
-- Name: TABLE fbi_ingestion; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.fbi_ingestion TO amateo_app;
GRANT SELECT ON TABLE public.fbi_ingestion TO amateo_read;


--
-- Name: TABLE feedback; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.feedback TO amateo_app;
GRANT SELECT ON TABLE public.feedback TO amateo_read;


--
-- Name: TABLE ffbb_committee; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.ffbb_committee TO amateo_app;
GRANT SELECT ON TABLE public.ffbb_committee TO amateo_read;


--
-- Name: TABLE ffbb_league; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.ffbb_league TO amateo_app;
GRANT SELECT ON TABLE public.ffbb_league TO amateo_read;


--
-- Name: TABLE fixture; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.fixture TO amateo_app;
GRANT SELECT ON TABLE public.fixture TO amateo_read;


--
-- Name: TABLE holiday_reminder_log; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.holiday_reminder_log TO amateo_app;


--
-- Name: TABLE implicit_rule_setting; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.implicit_rule_setting TO amateo_app;
GRANT SELECT ON TABLE public.implicit_rule_setting TO amateo_read;


--
-- Name: TABLE league_match_window; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.league_match_window TO amateo_app;
GRANT SELECT ON TABLE public.league_match_window TO amateo_read;


--
-- Name: TABLE match_constraint; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.match_constraint TO amateo_app;
GRANT SELECT ON TABLE public.match_constraint TO amateo_read;


--
-- Name: TABLE match_module_visit; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.match_module_visit TO amateo_app;
GRANT SELECT ON TABLE public.match_module_visit TO amateo_read;


--
-- Name: TABLE match_placement_run; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.match_placement_run TO amateo_app;
GRANT SELECT ON TABLE public.match_placement_run TO amateo_read;


--
-- Name: TABLE opponent_directory; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.opponent_directory TO amateo_app;
GRANT SELECT ON TABLE public.opponent_directory TO amateo_read;


--
-- Name: TABLE opponent_venue_link; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.opponent_venue_link TO amateo_app;
GRANT SELECT ON TABLE public.opponent_venue_link TO amateo_read;


--
-- Name: TABLE opponent_venue_suggestion; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.opponent_venue_suggestion TO amateo_app;
GRANT SELECT ON TABLE public.opponent_venue_suggestion TO amateo_read;


--
-- Name: TABLE period_reminder_log; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.period_reminder_log TO amateo_app;


--
-- Name: TABLE person; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.person TO amateo_app;
GRANT SELECT ON TABLE public.person TO amateo_read;


--
-- Name: TABLE place; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.place TO amateo_app;
GRANT SELECT ON TABLE public.place TO amateo_read;


--
-- Name: TABLE place_travel_time; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.place_travel_time TO amateo_app;
GRANT SELECT ON TABLE public.place_travel_time TO amateo_read;


--
-- Name: TABLE plan_constraint; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.plan_constraint TO amateo_app;
GRANT SELECT ON TABLE public.plan_constraint TO amateo_read;


--
-- Name: TABLE plan_team; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.plan_team TO amateo_app;
GRANT SELECT ON TABLE public.plan_team TO amateo_read;


--
-- Name: TABLE plan_venue; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.plan_venue TO amateo_app;
GRANT SELECT ON TABLE public.plan_venue TO amateo_read;


--
-- Name: TABLE priority_tier; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.priority_tier TO amateo_app;
GRANT SELECT ON TABLE public.priority_tier TO amateo_read;


--
-- Name: TABLE public_holiday; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.public_holiday TO amateo_app;
GRANT SELECT ON TABLE public.public_holiday TO amateo_read;


--
-- Name: TABLE release_note; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.release_note TO amateo_app;
GRANT SELECT ON TABLE public.release_note TO amateo_read;


--
-- Name: TABLE reservation; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.reservation TO amateo_app;
GRANT SELECT ON TABLE public.reservation TO amateo_read;


--
-- Name: TABLE reset_password_request; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.reset_password_request TO amateo_app;


--
-- Name: SEQUENCE reset_password_request_id_seq; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,USAGE ON SEQUENCE public.reset_password_request_id_seq TO amateo_app;


--
-- Name: TABLE schedule; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.schedule TO amateo_app;
GRANT SELECT ON TABLE public.schedule TO amateo_read;


--
-- Name: TABLE schedule_diagnostic; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.schedule_diagnostic TO amateo_app;
GRANT SELECT ON TABLE public.schedule_diagnostic TO amateo_read;


--
-- Name: TABLE schedule_plan; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.schedule_plan TO amateo_app;
GRANT SELECT ON TABLE public.schedule_plan TO amateo_read;


--
-- Name: TABLE schedule_slot_template; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.schedule_slot_template TO amateo_app;
GRANT SELECT ON TABLE public.schedule_slot_template TO amateo_read;


--
-- Name: TABLE schedule_state; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.schedule_state TO amateo_app;
GRANT SELECT ON TABLE public.schedule_state TO amateo_read;


--
-- Name: TABLE scheduled_deployment; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.scheduled_deployment TO amateo_app;
GRANT SELECT ON TABLE public.scheduled_deployment TO amateo_read;


--
-- Name: TABLE school_holiday_period; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.school_holiday_period TO amateo_app;
GRANT SELECT ON TABLE public.school_holiday_period TO amateo_read;


--
-- Name: TABLE season; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.season TO amateo_app;
GRANT SELECT ON TABLE public.season TO amateo_read;


--
-- Name: TABLE shared_competition_deadline; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.shared_competition_deadline TO amateo_app;
GRANT SELECT ON TABLE public.shared_competition_deadline TO amateo_read;


--
-- Name: TABLE shared_training_block; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.shared_training_block TO amateo_app;
GRANT SELECT ON TABLE public.shared_training_block TO amateo_read;


--
-- Name: TABLE shared_training_block_team; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.shared_training_block_team TO amateo_app;
GRANT SELECT ON TABLE public.shared_training_block_team TO amateo_read;


--
-- Name: TABLE solver_metrics; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.solver_metrics TO amateo_app;
GRANT SELECT ON TABLE public.solver_metrics TO amateo_read;


--
-- Name: TABLE sport; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.sport TO amateo_app;
GRANT SELECT ON TABLE public.sport TO amateo_read;


--
-- Name: TABLE sport_category; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.sport_category TO amateo_app;
GRANT SELECT ON TABLE public.sport_category TO amateo_read;


--
-- Name: TABLE subscription_plan; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT ON TABLE public.subscription_plan TO amateo_app;
GRANT SELECT ON TABLE public.subscription_plan TO amateo_read;


--
-- Name: TABLE subscription_plan_tier; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT ON TABLE public.subscription_plan_tier TO amateo_app;
GRANT SELECT ON TABLE public.subscription_plan_tier TO amateo_read;


--
-- Name: TABLE team; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.team TO amateo_app;
GRANT SELECT ON TABLE public.team TO amateo_read;


--
-- Name: TABLE team_coach; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.team_coach TO amateo_app;
GRANT SELECT ON TABLE public.team_coach TO amateo_read;


--
-- Name: TABLE team_link; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.team_link TO amateo_app;
GRANT SELECT ON TABLE public.team_link TO amateo_read;


--
-- Name: TABLE team_match_habit; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.team_match_habit TO amateo_app;
GRANT SELECT ON TABLE public.team_match_habit TO amateo_read;


--
-- Name: TABLE transition_reminder_log; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.transition_reminder_log TO amateo_app;


--
-- Name: TABLE venue; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.venue TO amateo_app;
GRANT SELECT ON TABLE public.venue TO amateo_read;


--
-- Name: TABLE venue_match_window; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.venue_match_window TO amateo_app;
GRANT SELECT ON TABLE public.venue_match_window TO amateo_read;


--
-- Name: TABLE venue_occupancy; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.venue_occupancy TO amateo_app;
GRANT SELECT ON TABLE public.venue_occupancy TO amateo_read;


--
-- Name: TABLE venue_training_slot; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.venue_training_slot TO amateo_app;
GRANT SELECT ON TABLE public.venue_training_slot TO amateo_read;


--
-- Name: TABLE venue_travel_rule_setting; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.venue_travel_rule_setting TO amateo_app;
GRANT SELECT ON TABLE public.venue_travel_rule_setting TO amateo_read;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: public; Owner: -
--

ALTER DEFAULT PRIVILEGES FOR ROLE amateo_owner IN SCHEMA public GRANT SELECT,USAGE ON SEQUENCES TO amateo_app;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: public; Owner: -
--

ALTER DEFAULT PRIVILEGES FOR ROLE amateo_owner IN SCHEMA public GRANT SELECT,INSERT,DELETE,UPDATE ON TABLES TO amateo_app;


--
-- PostgreSQL database dump complete
--



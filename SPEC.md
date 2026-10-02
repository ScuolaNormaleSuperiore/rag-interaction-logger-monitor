# Specifica — Plugin WordPress “RAG Interaction Logger Monitor”

Ultimo aggiornamento: 2026-10-02

## Scopo

Plugin WordPress indipendente per ICT SNS che funge da backoffice di RAG Interaction Logger per Cheshire Cat AI. Consente agli amministratori di leggere, cercare e analizzare i log nella tabella MySQL/MariaDB ril_interactions. Serve a individuare anomalie e a ricostruire nel dettaglio le singole interazioni RAG.

È solo amministrativo: nessun frontend, shortcode, blocco, endpoint pubblico o dipendenza dal tema.

## Vincoli confermati

| Aspetto | Decisione |
| --- | --- |
| Sito | https://ict.sns.it |
| WordPress | 7.1.2 |
| PHP | 8.3 o superiore |
| Nome plugin | RAG Interaction Logger Monitor |
| Slug plugin | rag-interaction-logger-monitor |
| Prefisso codice | `rilm_` |
| Distribuzione | Repository GitHub dedicato |
| Database | Raggiungibile dal server WordPress |
| Accesso | Solo amministratori WordPress |
| Testi | Completi nel dettaglio; anteprima espandibile nell'elenco |
| Monitoraggio | Manuale nella v1; nessun alert |
| Tema | Nessuna influenza |
| Volume | Migliaia di righe |

## Fonte dati e confini

La sola fonte della v1 è ril_interactions, con lo schema del repository rag-interaction-logger, definito in [schema.py](https://github.com/ScuolaNormaleSuperiore/rag-interaction-logger/blob/main/schema.py). Il monitor non modifica mai la tabella, non crea tabelle applicative e non esegue retention, purge, correzioni o backfill.

I campi visualizzati includono: id, timestamp UTC, instance, user_id, outcome, durata, turn id, question, llm_answer, delivered, stato e verdict Guardrails, other_plugin_reply, recall_count e recall_top_score.

Le colonne tools_used, tool_input, tool_output e recall_sources sono state aggiunte alla tabella in seguito e possono mancare nelle installazioni più vecchie: nella v1 compaiono solo nel Dettaglio, quando esistono, e non sono usate in elenco, filtri, ricerca o Dashboard.

Question, llm_answer, delivered e user_id possono contenere dati personali; i contenuti completi restano visibili solo agli amministratori.

## Sicurezza e configurazione

- Ogni pagina richiede manage_options. Non esistono pagine pubbliche né REST API pubbliche.
- Ogni form richiede capability e nonce WordPress.
- Il DB usa un utente distinto dal logger, con solo SELECT sulla tabella configurata (default rag-interaction-logger-db.ril_interactions). Non sono ammessi INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, GRANT o altri privilegi di amministrazione.
- Host, porta, database, tabella e utente sono impostabili dalla pagina Impostazioni e salvati in un'unica opzione WordPress (`rilm_settings`); se la costante corrispondente è definita in wp-config.php, la costante ha la precedenza e il campo è mostrato in sola lettura.
- La password del database si definisce con la costante `ICT_RAG_MONITOR_DB_PASSWORD` in wp-config.php (ha la precedenza e blocca il campo) oppure dalla pagina Impostazioni, dove è salvata cifrata in un'opzione separata (`rilm_db_password`, senza autoload, non esposta via REST). La cifratura usa libsodium (`secretbox`, cifratura autenticata) con una chiave derivata da `SECURE_AUTH_KEY` e `SECURE_AUTH_SALT` di wp-config.php, che non sono salvate nel database. Il campo non mostra mai né la password né il testo cifrato: lasciarlo vuoto mantiene quella salvata e un pulsante, protetto da nonce, la rimuove. Senza chiavi di sicurezza uniche (assenti o ancora quelle di esempio) la pagina non salva la password e invita a definirle o a usare la costante. Limite: la cifratura protegge da una fuga del solo database (dump, backup, SQL injection altrove), non da chi può leggere anche wp-config.php.
- La pagina Impostazioni richiede manage_options, usa la Settings API di WordPress (nonce incluso) e non mostra mai la password.
- La password non appare mai in HTML, log, diagnostica o errori. Gli errori admin sono generici e non espongono query, credenziali, testi delle interazioni o dettagli del driver.
- Il monitor usa le stesse impostazioni TLS e l'infrastruttura della connessione database del sito WordPress; non introduce una configurazione TLS propria.
- Il monitor apre una seconda connessione `wpdb`, distinta da quella del sito. Una piccola sottoclasse interna evita che un DB dei log non raggiungibile termini la richiesta: nell'admin appare un avviso sicuro e il sito continua a funzionare.

Costanti, tutte facoltative: ICT_RAG_MONITOR_DB_PASSWORD, ICT_RAG_MONITOR_DB_HOST, ICT_RAG_MONITOR_DB_PORT, ICT_RAG_MONITOR_DB_NAME, ICT_RAG_MONITOR_DB_TABLE e ICT_RAG_MONITOR_DB_USER. Se definite sostituiscono il valore delle Impostazioni (la password salvata dalle Impostazioni, nel caso di ICT_RAG_MONITOR_DB_PASSWORD). Una password deve però esistere in uno dei due modi per poter connettersi.

Valori di default: porta `3306`, database `rag-interaction-logger-db`, tabella `ril_interactions`; l'host non ha default e deve venire da costante o Impostazioni. Il nome della tabella è un identificatore SQL, non un valore preparabile: va validato con una whitelist (solo lettere, numeri e underscore) e quotato con backtick; un valore non valido produce un avviso admin generico, senza query.

## Funzionalità v1

### Menu Monitor RAG

1. Dashboard: indicatori, serie giornaliere e collegamenti alle anomalie.
2. Interazioni: elenco paginato, filtrabile e ricercabile.
3. Dettaglio interazione: consultazione completa di una riga.
4. Anomalie: viste predefinite che applicano filtri a Interazioni.
5. Impostazioni: host, porta, database, tabella e utente della connessione ai log.

La password si imposta dalla stessa pagina (salvata cifrata) oppure con la costante di wp-config.php; la pagina mostra solo da dove proviene (costante, salvata, non leggibile, assente), mai il suo valore.

### Dashboard

Per l'intervallo selezionato, mostrare:

- totale turni;
- conteggio e percentuale di generated, fast_reply e incomplete;
- blocchi input/output per verdict;
- conteggio e percentuale senza Guardrails;
- durata media e mediana esatta dei turni completati (outcome diverso da incomplete e duration_ms non NULL, quindi generated e fast_reply);
- percentuale generated con recall_count uguale a zero;
- andamento giornaliero di turni, incomplete e blocchi;
- link alle viste Interazioni già filtrate.

L'intervallo predefinito è oggi: da mezzanotte nel fuso WordPress fino all'istante corrente. Il selettore offre oggi, ultima settimana, ultimo mese, ultimi 3 mesi, ultimi 6 mesi, ultimo anno e un intervallo personalizzato con data e ora di inizio e fine. Non esiste un limite massimo per l'intervallo personalizzato.

I valori NULL devono essere distinti dai valori espliciti: verdict NULL significa nessun blocco registrato, mentre other_plugin_reply NULL può indicare Guardrails assente.

### Elenco Interazioni

Ordinamento predefinito ts DESC e paginazione lato database. L'intervallo predefinito è oggi. Colonne: data/ora locale, esito, instance, user id, durata, stato Guardrails, verdict input/output, anteprima domanda e anteprima risposta consegnata.

Le anteprime sono troncate visivamente ed espandibili senza lasciare l'elenco. Il dettaglio offre sempre la vista completa.

Filtri combinabili: periodo, outcome, instance, user id, Guardrails presente/assente, verdict input/output, other_plugin_reply, recall vuoto e ricerca testuale.

La ricerca libera cerca in question, llm_answer e delivered con LIKE case-insensitive, parametrizzato e con wildcard costruiti in modo sicuro. Il testo cercato è inviato con POST e nonce WordPress, così non compare nell'URL; gli altri filtri non sensibili possono restare nell'URL. Non è previsto FULLTEXT nella v1: con migliaia di righe è adeguato. Tutti i valori delle query sono parametrizzati; le colonne ordinabili sono definite da una whitelist interna.

### Dettaglio interazione

Mostrare senza troncamento domanda, risposta generata, risposta consegnata e confronto visivo quando le due risposte differiscono. Mostrare inoltre data/ora UTC e locale WordPress, id, instance, user id, turn id, outcome, durata, stato/verdict Guardrails, other_plugin_reply e recall.

L'id è validato come intero positivo; una riga inesistente o un utente non autorizzato non espongono dati.

### Anomalie

Viste predefinite:

- incomplete;
- guard_present falso;
- blocchi input;
- blocchi output;
- generated in cui llm_answer e delivered differiscono senza verdict output;
- generated con recall_count uguale a zero.

Le viste non inviano notifiche e non modificano dati.

## Date, prestazioni e architettura

Il timestamp è UTC. I confini dei filtri vengono convertiti in UTC per le query; nell'admin le date sono mostrate nel fuso configurato in WordPress, esplicitamente dichiarato nella UI.

L'elenco non carica mai l'intera tabella: richiede filtro temporale e paginazione. Gli indici esistenti su ts, user_id con ts e verdict sono adeguati per dashboard e filtri principali. LIKE con wildcard iniziale non è indicizzato e va limitato all'intervallo selezionato. Se il volume crescerà, ricerca e indici saranno rivalutati con il DBA.

Struttura proposta:

- bootstrap con header WordPress e menu admin;
- configurazione: host, porta, database, tabella e utente da opzione WordPress; password da costante di wp-config.php oppure salvata cifrata in un'opzione separata; le costanti omonime hanno sempre la precedenza;
- repository read-only per SQL, filtri e mapping delle righe;
- pagine/controller separati per dashboard, elenco e dettaglio;
- JavaScript amministrativo minimo per espansioni e grafici, caricato solo nelle pagine del plugin;
- CSS amministrativo minimo, basato sugli elementi nativi WordPress e caricato solo nel backend del plugin.

## Fuori ambito v1

Alert, email, cron, export, download dei testi, scrittura o cancellazione log, retention, provisioning DB/utenti, frontend, blocchi, shortcode, API REST pubbliche, conversazioni persistite, FULLTEXT, capability delegata e audit degli accessi.

## Criteri di accettazione

1. Solo chi possiede manage_options accede alle pagine del plugin (compresa Impostazioni).
2. Il plugin esegue esclusivamente SELECT sui log.
3. Dashboard, filtri e statistiche sono corretti per l'intervallo scelto.
4. La ricerca trova testo in domanda, risposta LLM e risposta consegnata senza SQL injection.
5. L'elenco è paginato e i testi lunghi sono espandibili.
6. Il dettaglio mostra tutti i testi e metadati corretti della riga.
7. Date locali e confini UTC corrispondono correttamente.
8. Credenziali non compaiono mai nell'interfaccia o nei log.
9. DB non raggiungibile: errore admin sicuro, nessun fatal e nessun impatto sul frontend.
10. Una connessione effettuata con l'utente DB dedicato non può eseguire operazioni diverse da SELECT sui log.

## Decisioni da verificare prima della distribuzione

- Validazione DBA di host, impostazioni TLS del sito WordPress e privilegio SELECT minimo.
- Eventuale capability dedicata e audit accessi futuri.
- Soglia di volume oltre la quale evolvere ricerca e indici.


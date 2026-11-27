import { existsSync, readFileSync, writeFileSync, mkdirSync, rmSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import readline from "node:readline/promises";
import { stdin as input, stdout as output } from "node:process";

// --- Valeurs par défaut configurables en tête de script ---
const DEFAULT_REMOTE_DIR = "~/KingdomCreator/Follow";
const DEFAULT_PHP_CLI = "/usr/bin/php8.5-cli";
const DEFAULT_KEYS_SUBDIR = "keys";
const DEFAULT_ENV_FILENAME = ".env.do_not_commit";
const migrationArguments = process.argv.slice(2);
const migrationArgumentSet = new Set(migrationArguments);
const migrationOptions = [
  "--migrate-database",
  "--skip-database-migration",
  "--migrate-remaining-databases",
];
const migrationChoiceOptions = [
  "--migrate-database",
  "--skip-database-migration",
];
if (
  migrationArguments.some((argument) => !migrationOptions.includes(argument)) ||
  migrationArguments.length !== migrationArgumentSet.size ||
  migrationChoiceOptions.every((argument) => migrationArgumentSet.has(argument))
) {
  console.error(
    "Usage: bun run deploy:follow [--migrate-database | --skip-database-migration] [--migrate-remaining-databases]",
  );
  process.exit(2);
}
const requestedMigration = migrationArgumentSet.has("--migrate-database")
  ? true
  : migrationArgumentSet.has("--skip-database-migration")
    ? false
    : null;
const migrateRemainingDatabases = migrationArgumentSet.has("--migrate-remaining-databases");

const dashboardDirectory = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const repositoryRoot = resolve(dashboardDirectory, "..", "..", "..");
const keysDir = join(dashboardDirectory, "keys");
const envPath = join(keysDir, DEFAULT_ENV_FILENAME);
const gitignorePath = resolve(dashboardDirectory, ".gitignore");

async function run(executable, args, label, inheritStdin = true, cwd = repositoryRoot) {
  console.log(`➡️ ${label}`);
  const proc = Bun.spawn([executable, ...args], {
    stdin: inheritStdin ? "inherit" : "ignore",
    stdout: "inherit",
    stderr: "inherit",
    cwd,
  });
  const exitCode = await proc.exited;
  if (exitCode !== 0) throw new Error(`${label} a échoué (code ${exitCode}).`);
}

// Fonction d'assistant de configuration initiale
async function runSetup() {
  console.log("=== Assistant de configuration SSH & Déploiement IONOS ===");
  console.log("Aucune configuration valide trouvée. Paramétrage initial requis...\n");

  const rl = readline.createInterface({ input, output });

  let sshUser, sshHost;
  try {
    sshUser = (await rl.question("Nom d'utilisateur SSH IONOS (ex: u12345678) : ")).trim();
    sshHost = (await rl.question("Hôte SSH IONOS (ex: mcv.net ou ssh.cluster...) : ")).trim();
  } finally {
    rl.close();
  }

  if (!sshUser || !sshHost) {
    console.error("Erreur : L'utilisateur et l'hôte SSH sont obligatoires.");
    process.exit(1);
  }

  const sshExecutable = Bun.which("ssh");
  const sshGenExecutable = Bun.which("ssh-keygen");

  if (!sshExecutable || !sshGenExecutable) {
    console.error("Erreur : Les commandes 'ssh' et 'ssh-keygen' sont introuvables sur le système.");
    process.exit(1);
  }

  // 1. Création du dossier keys et génération de la clé SSH
  if (!existsSync(keysDir)) {
    mkdirSync(keysDir, { recursive: true });
  }

  const keyPath = join(keysDir, "ionos_follow");
  const pubKeyPath = `${keyPath}.pub`;

  if (!existsSync(keyPath)) {
    console.log(`Génération de la paire de clés SSH dans ${DEFAULT_KEYS_SUBDIR} (ionos_follow)...`);
    const genProc = Bun.spawn([sshGenExecutable, "-t", "ed25519", "-a", "64", "-f", keyPath, "-N", "", "-C", "ionos-follow"], {
      stdout: "inherit",
      stderr: "inherit",
    });
    if ((await genProc.exited) !== 0) {
      console.error("Erreur lors de la génération de la clé SSH.");
      process.exit(1);
    }
  }

  const pubKeyContent = readFileSync(pubKeyPath, "utf8").trim();
  const destination = `${sshUser}@${sshHost}`;

  console.log("\n--- Authentification initiale unique ---");
  console.log("Pour autoriser votre clé sur le serveur IONOS, veuillez saisir votre mot de passe SSH IONOS.");
  console.log("Celui-ci NE SERA PAS stocké.\n");

  const remoteSetupCommand = [
    "mkdir -p -m 700 ~/.ssh",
    `grep -v 'ionos-follow' ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.tmp 2>/dev/null || true`,
    `mv ~/.ssh/authorized_keys.tmp ~/.ssh/authorized_keys 2>/dev/null || true`,
    `echo '${pubKeyContent}' >> ~/.ssh/authorized_keys`,
    "chmod 600 ~/.ssh/authorized_keys",
    "echo 'Clé SSH installée avec succès sur le serveur distant !'"
  ].join(" && ");

  try {
    await run(sshExecutable, [destination, remoteSetupCommand], "Connexion au serveur et installation de la clé SSH");
  } catch (error) {
    console.error("\n❌ Échec de la connexion ou de la configuration de la clé.");
    process.exit(1);
  }

  // 2. Enregistrement des paramètres dans le fichier d'environnement
  const envContent = [
    `# Configuration de déploiement IONOS (Généré automatiquement)`,
    `IONOS_SSH_USER=${sshUser}`,
    `IONOS_SSH_HOST=${sshHost}`,
    `IONOS_REMOTE_DIR=${DEFAULT_REMOTE_DIR}`,
    `IONOS_SSH_KEY=${keyPath}`,
    `IONOS_PHP_CLI=${DEFAULT_PHP_CLI}`,
    ``
  ].join("\n");

  writeFileSync(envPath, envContent, "utf8");

  // 3. Sécurité : Une seule entrée dans .gitignore couvre tout le dossier keys/
  if (existsSync(gitignorePath)) {
    let gitignore = readFileSync(gitignorePath, "utf8");
    const ignoreEntry = `/${DEFAULT_KEYS_SUBDIR}/`;

    if (!gitignore.split(/\r?\n/).some((l) => l.trim() === ignoreEntry)) {
      gitignore = `${gitignore.trim()}\n${ignoreEntry}\n`;
      writeFileSync(gitignorePath, gitignore, "utf8");
      console.log(`🔒 Entrée '${ignoreEntry}' ajoutée dans .gitignore.`);
    }
  }

  console.log(`✅ Configuration enregistrée dans ${DEFAULT_KEYS_SUBDIR}/${DEFAULT_ENV_FILENAME}.\n`);
}

// --- Étape de vérification initiale avant exécution ---

function loadEnvFile() {
  if (!existsSync(envPath)) return false;
  const envContent = readFileSync(envPath, "utf8");
  let hasValidData = false;
  for (const line of envContent.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) continue;
    const eqIndex = trimmed.indexOf("=");
    if (eqIndex > 0) {
      const key = trimmed.slice(0, eqIndex).trim();
      let val = trimmed.slice(eqIndex + 1).trim();
      if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
        val = val.slice(1, -1);
      }
      if (val) {
        process.env[key] = val;
        if (key === "IONOS_SSH_USER" || key === "IONOS_SSH_HOST" || key === "IONOS_SSH_KEY") {
          hasValidData = true;
        }
      }
    }
  }
  return hasValidData;
}

// Si le fichier n'existe pas ou est incomplet, on déclenche le setup automatiquement
if (!existsSync(envPath) || !loadEnvFile() || !process.env.IONOS_SSH_KEY || !existsSync(process.env.IONOS_SSH_KEY)) {
  if (existsSync(envPath)) {
    try { rmSync(envPath); } catch (e) {}
  }
  await runSetup();
  loadEnvFile(); // Rechargement après setup
}

// --- Logique de Déploiement ---

const sshUser = process.env.IONOS_SSH_USER;
const sshHost = process.env.IONOS_SSH_HOST;
const remoteDirectory = process.env.IONOS_REMOTE_DIR || DEFAULT_REMOTE_DIR;
const sshKey = process.env.IONOS_SSH_KEY;
const phpCli = process.env.IONOS_PHP_CLI || DEFAULT_PHP_CLI;
const dryRun = process.env.IONOS_DEPLOY_DRY_RUN === "1";
const setupOnly = process.env.IONOS_DEPLOY_SETUP_ONLY === "1";

function fail(message, resetConfig = false) {
  console.error(`Déploiement annulé : ${message}`);
  if (resetConfig && existsSync(envPath)) {
    console.log("🔄 Suppression de la configuration corrompue. Elle sera recréée au prochain lancement.");
    try { rmSync(envPath); } catch (e) {}
  }
  process.exit(1);
}

if (!sshUser || !sshHost || !sshKey || !existsSync(sshKey)) {
  fail("Paramètres de connexion invalides ou manquants.", true);
}

if (dryRun && setupOnly) {
  console.log("Initialisation terminée en simulation — aucun build ni déploiement effectué.");
  process.exit(0);
}

await run(process.execPath, ["run", "build"], "   Construction du dashboard Vue", false, dashboardDirectory);

const sourceDirectory = dashboardDirectory;
const files = [
  "server.php",
  "dashboard.php",
  "analytics-common.php",
  "analytics-reporting.php",
  "database-migration.php",
  "scripts/prepare-database-v7.2.php",
  "scripts/import-database-v7.2.php",
  "scripts/migrate-remaining-databases-v7.2.php",
  "dist",
];
const localFiles = files.map((file) => join(sourceDirectory, file));
for (const file of localFiles) {
  if (!existsSync(file)) fail(`Fichier local introuvable : ${file}`);
}
if (!existsSync(join(sourceDirectory, "dist", "index.html"))) {
  fail("Le build du dashboard ne contient pas dist/index.html.");
}

const remoteRelativeDirectory = remoteDirectory.slice(2);
const destination = `${sshUser}@${sshHost}`;
const releaseId = `${Date.now()}-${process.pid}`;
const remoteStage = `~/${remoteRelativeDirectory}/.deploy-${releaseId}`;
const connectionArgs = ["-i", sshKey, "-o", "BatchMode=yes"];

const sshExecutable = process.env.IONOS_SSH_EXE || Bun.which("ssh");
const scpExecutable = process.env.IONOS_SCP_EXE || Bun.which("scp");

if (dryRun) {
  console.log("Simulation uniquement — aucun transfert effectué.");
  console.log(`Cible : ${destination}:${remoteDirectory}`);
  process.exit(0);
}

if (!sshExecutable || !scpExecutable) {
  fail("ssh ou scp est introuvable sur le système.");
}

async function execStep(executable, args, label, captureStdout = false) {
  console.log(`➡️  ${label}`);
  const proc = Bun.spawn([executable, ...args], {
    stdin: "inherit",
    stdout: captureStdout ? "pipe" : "inherit",
    stderr: "pipe",
  });
  let stderrOutput = "";
  let stdoutOutput = "";
  const stdoutDecoder = new TextDecoder();
  const stderrDecoder = new TextDecoder();
  const readStdout = async () => {
    if (!captureStdout || !proc.stdout) return;
    for await (const chunk of proc.stdout) {
      stdoutOutput += stdoutDecoder.decode(chunk, { stream: true });
    }
    stdoutOutput += stdoutDecoder.decode();
  };
  // Lecture asynchrone du flux d'erreur pour l'afficher en direct ET le stocker
  const readStderr = async () => {
    for await (const chunk of proc.stderr) {
      const text = stderrDecoder.decode(chunk, { stream: true });
      stderrOutput += text;
      process.stderr.write(text); // Affiche à l'écran en temps réel
    }
  };

  const stdoutPromise = readStdout();
  const stderrPromise = readStderr();
  const exitCode = await proc.exited;
  await stdoutPromise;
  await stderrPromise; // On s'assure que tout le flux est bien lu
  if (exitCode !== 0) {
    // stderrOutput contient maintenant toute l'erreur exacte (ex: Could not resolve hostname...)
    const fullErrorMsg = stderrOutput.trim();
    throw new Error(`${label} a échoué (code ${exitCode}).\nDétails : ${fullErrorMsg}`);
  }
  return stdoutOutput.trim();
}

async function askForMigration() {
  if (requestedMigration !== null) return requestedMigration;
  if (!process.stdin.isTTY || !process.stdout.isTTY) {
    console.log(
      "Session non interactive : migration ignorée. Utilisez --migrate-database pour la demander explicitement.",
    );
    return false;
  }

  const rl = readline.createInterface({ input, output });
  try {
    const answer = (await rl.question(
      "La base principale doit être migrée. Exécuter cette migration maintenant ? (o/N) ",
    )).trim();
    return /^o(ui)?$/i.test(answer);
  } finally {
    rl.close();
  }
}

  const cleanupRemoteStageCommand = [
    "set -eu",
    `destination="$HOME/${remoteRelativeDirectory}"`,
    `stage="$HOME/${remoteRelativeDirectory}/.deploy-${releaseId}"`,
    `rm -f -- "$stage/server.php" "$stage/dashboard.php" "$stage/analytics-common.php" "$stage/analytics-reporting.php" "$stage/database-migration.php" "$stage/prepare-database-v7.2.php" "$stage/import-database-v7.2.php" "$stage/migrate-remaining-databases-v7.2.php" "$destination/.server.php.${releaseId}" "$destination/.dashboard.php.${releaseId}" "$destination/.analytics-common.php.${releaseId}" "$destination/.analytics-reporting.php.${releaseId}" "$destination/.database-migration.php.${releaseId}" "$destination/scripts/.prepare-database-v7.2.php.${releaseId}" "$destination/scripts/.import-database-v7.2.php.${releaseId}" "$destination/scripts/.migrate-remaining-databases-v7.2.php.${releaseId}" "$destination/.index.html.${releaseId}"`,
    'rm -rf -- "$stage/dist"',
    `rmdir -- "$stage"`,
  ].join("\n");
  
  //await execStep(sshExecutable, [...connectionArgs, destination, command], "Nettoyage du dossier temporaire distant…");

const createStageCommand = [
  "set -eu",
  `destination="$HOME/${remoteRelativeDirectory}"`,
  'mkdir -p -- "$destination/databases"',
  `mkdir -m 700 -- "$destination/.deploy-${releaseId}"`,
].join("\n");

const installToolsCommand = [
  "set -eu",
  `destination="$HOME/${remoteRelativeDirectory}"`,
  `stage="$destination/.deploy-${releaseId}"`,
  `phpCli="${phpCli}"`,
  'test -f "$stage/server.php"',
  'test -f "$stage/dashboard.php"',
  'test -f "$stage/analytics-common.php"',
  'test -f "$stage/analytics-reporting.php"',
  'test -f "$stage/database-migration.php"',
  'test -f "$stage/prepare-database-v7.2.php"',
  'test -f "$stage/import-database-v7.2.php"',
  'test -f "$stage/migrate-remaining-databases-v7.2.php"',
  'test -f "$stage/dist/index.html"',
  '"$phpCli" -l "$stage/server.php"',
  '"$phpCli" -l "$stage/dashboard.php"',
  '"$phpCli" -l "$stage/analytics-common.php"',
  '"$phpCli" -l "$stage/analytics-reporting.php"',
  '"$phpCli" -l "$stage/database-migration.php"',
  '"$phpCli" -l "$stage/prepare-database-v7.2.php"',
  '"$phpCli" -l "$stage/import-database-v7.2.php"',
  '"$phpCli" -l "$stage/migrate-remaining-databases-v7.2.php"',
  'mkdir -p -- "$destination/scripts"',
  `install -m 0644 -- "$stage/analytics-common.php" "$destination/.analytics-common.php.${releaseId}"`,
  `mv -f -- "$destination/.analytics-common.php.${releaseId}" "$destination/analytics-common.php"`,
  `install -m 0644 -- "$stage/analytics-reporting.php" "$destination/.analytics-reporting.php.${releaseId}"`,
  `mv -f -- "$destination/.analytics-reporting.php.${releaseId}" "$destination/analytics-reporting.php"`,
  `install -m 0644 -- "$stage/database-migration.php" "$destination/.database-migration.php.${releaseId}"`,
  `mv -f -- "$destination/.database-migration.php.${releaseId}" "$destination/database-migration.php"`,
  `install -m 0644 -- "$stage/prepare-database-v7.2.php" "$destination/scripts/.prepare-database-v7.2.php.${releaseId}"`,
  `mv -f -- "$destination/scripts/.prepare-database-v7.2.php.${releaseId}" "$destination/scripts/prepare-database-v7.2.php"`,
  `install -m 0644 -- "$stage/import-database-v7.2.php" "$destination/scripts/.import-database-v7.2.php.${releaseId}"`,
  `mv -f -- "$destination/scripts/.import-database-v7.2.php.${releaseId}" "$destination/scripts/import-database-v7.2.php"`,
  `install -m 0644 -- "$stage/migrate-remaining-databases-v7.2.php" "$destination/scripts/.migrate-remaining-databases-v7.2.php.${releaseId}"`,
  `mv -f -- "$destination/scripts/.migrate-remaining-databases-v7.2.php.${releaseId}" "$destination/scripts/migrate-remaining-databases-v7.2.php"`,
].join("\n");

const migrationStatusCommand = [
  "set -eu",
  `destination="$HOME/${remoteRelativeDirectory}"`,
  `phpCli="${phpCli}"`,
  `"$phpCli" "$destination/scripts/import-database-v7.2.php" --status dominionrandomizer.com`,
].join("\n");

function createInstallCommand(migrateDatabase, migrateRemaining) {
  const migrationSteps = migrateDatabase
    ? [
      `pausedServer="$destination/.server.php.paused.${releaseId}"`,
      `pausedDashboard="$destination/.dashboard.php.paused.${releaseId}"`,
      'restore_endpoints() { if [ -f "$pausedServer" ] && { [ ! -f "$destination/server.php" ] || [ ! -f "$destination/dashboard.php" ]; }; then rm -f -- "$destination/server.php" "$destination/dashboard.php"; mv -- "$pausedServer" "$destination/server.php"; if [ -f "$pausedDashboard" ]; then mv -- "$pausedDashboard" "$destination/dashboard.php"; fi; elif [ -f "$pausedDashboard" ] && [ ! -f "$destination/dashboard.php" ]; then mv -- "$pausedDashboard" "$destination/dashboard.php"; fi; }',
      'trap restore_endpoints EXIT',
      'if [ -f "$destination/server.php" ]; then mv -- "$destination/server.php" "$pausedServer"; fi',
      'if [ -f "$destination/dashboard.php" ]; then mv -- "$destination/dashboard.php" "$pausedDashboard"; fi',
      `"$phpCli" "$destination/scripts/prepare-database-v7.2.php" dominionrandomizer.com`,
    ]
    : [];
  const installServer = [
    `install -m 0644 -- "$stage/server.php" "$destination/.server.php.${releaseId}"`,
    `mv -f -- "$destination/.server.php.${releaseId}" "$destination/server.php"`,
  ];
  const installDashboard = [
    `install -m 0644 -- "$stage/dashboard.php" "$destination/.dashboard.php.${releaseId}"`,
    `mv -f -- "$destination/.dashboard.php.${releaseId}" "$destination/dashboard.php"`,
  ];
  const importSteps = migrateDatabase
    ? [`"$phpCli" "$destination/scripts/import-database-v7.2.php" dominionrandomizer.com`]
    : [];
  const remainingMigrationSteps = migrateRemaining
    ? [`"$phpCli" "$destination/scripts/migrate-remaining-databases-v7.2.php"`]
    : [];
  const cleanupMigrationScripts = [
    'rm -f -- "$destination/scripts/prepare-database-v7.2.php" "$destination/scripts/import-database-v7.2.php" "$destination/scripts/migrate-remaining-databases-v7.2.php" "$destination/scripts/backup-databases-v7.2.php" "$destination/scripts/migrate-databases-v7.2.php"',
  ];
  return [
    "set -eu",
    `destination="$HOME/${remoteRelativeDirectory}"`,
    `stage="$destination/.deploy-${releaseId}"`,
    `phpCli="${phpCli}"`,
    ...migrationSteps,
    ...(migrateDatabase ? [...installServer, ...installDashboard] : []),
    ...importSteps,
    ...remainingMigrationSteps,
    ...(!migrateDatabase ? installDashboard : []),
    'if [ -d "$stage/dist/assets" ]; then mkdir -p -- "$destination/assets"; cp -R -- "$stage/dist/assets/." "$destination/assets/"; fi',
    `install -m 0644 -- "$stage/dist/index.html" "$destination/.index.html.${releaseId}"`,
    `mv -f -- "$destination/.index.html.${releaseId}" "$destination/index.html"`,
    ...(!migrateDatabase ? installServer : []),
    'if [ -d "$stage/dist/assets" ] && [ -d "$destination/assets" ]; then find "$destination/assets" -type f -print | while IFS= read -r oldAsset; do relativeAsset=${oldAsset#"$destination/assets/"}; if [ ! -f "$stage/dist/assets/$relativeAsset" ]; then rm -f -- "$oldAsset"; fi; done; fi',
    ...cleanupMigrationScripts,
    'if [ -d "$destination/scripts" ] && [ -z "$(find "$destination/scripts" -mindepth 1 -maxdepth 1 -print -quit)" ]; then rmdir -- "$destination/scripts"; fi',
    ...(migrateDatabase ? ['rm -f -- "$pausedServer" "$pausedDashboard"'] : []),
    'rm -f -- "$stage/server.php" "$stage/dashboard.php" "$stage/analytics-common.php" "$stage/analytics-reporting.php" "$stage/database-migration.php" "$stage/prepare-database-v7.2.php" "$stage/import-database-v7.2.php" "$stage/migrate-remaining-databases-v7.2.php"',
    'rm -rf -- "$stage/dist"',
    'rmdir -- "$stage"',
    "printf '%s\\n' 'Déploiement terminé.'",
  ].join("\n");
}

let stageCreated = false;

try {
  await execStep(sshExecutable, [...connectionArgs, destination, createStageCommand], "  Vérification / création des dossiers distants et préparation du dépôt temporaire…");
  stageCreated = true;
  await execStep(scpExecutable, ["-r", ...connectionArgs, ...localFiles, `${destination}:${remoteStage}/`], "  Transfert de l’API, des migrations et du build dist/…");
  await execStep(sshExecutable, [...connectionArgs, destination, installToolsCommand], "  Validation PHP et installation des outils de migration…");

  const statusOutput = await execStep(
    sshExecutable,
    [...connectionArgs, destination, migrationStatusCommand],
    "  Vérification de la version de migration de la base principale…",
    true,
  );
  const migrationStatus = statusOutput.match(
    /migration-status=(complete|pending|not-needed);database-version=(\d+)/,
  );
  if (!migrationStatus) {
    throw new Error(`Réponse de statut de migration IONOS invalide : ${statusOutput}`);
  }

  const [, databaseStatus, databaseVersion] = migrationStatus;
  let migrateDatabase = false;
  if (databaseStatus === "complete") {
    console.log(`La base principale est déjà migrée (version ${databaseVersion}) : aucune migration ne sera relancée.`);
  } else if (databaseStatus === "not-needed") {
    console.log("Aucune base ni archive existante : migration non nécessaire.");
  } else {
    migrateDatabase = await askForMigration();
    console.log(migrateDatabase
      ? "  La migration de la base principale est demandée."
      : "  Migration ignorée ; les données existantes ne seront pas déplacées ni importées.");
  }

  await execStep(
    sshExecutable,
    [...connectionArgs, destination, createInstallCommand(migrateDatabase, migrateRemainingDatabases)],
    migrateDatabase || migrateRemainingDatabases
      ? "  Migration demandée, nettoyage des scripts temporaires et installation du dashboard…"
      : "  Installation du serveur et du dashboard sans migration de base…",
  );
} catch (error) {
  if (stageCreated) {
    try { 
		await execStep(sshExecutable, [...connectionArgs, destination, cleanupRemoteStageCommand], "  Nettoyage du dossier temporaire distant…");
	} catch (e) {}
  }
  console.error(error instanceof Error ? error.message : error);

  const errMsg = error.message;
  if (errMsg.includes("exited (code 255)") || errMsg.includes("Permission denied")) {
    console.log("🔄 Erreur d'accès SSH détectée. Réinitialisation de la configuration...");
    if (existsSync(envPath)) rmSync(envPath);
  } else if (errMsg.includes("Could not resolve hostname")) {
    console.log("🔄 Erreur d'accès au HOST. Réinitialisation de la configuration...")
    if (existsSync(envPath)) rmSync(envPath);
  }
  process.exit(1);
}
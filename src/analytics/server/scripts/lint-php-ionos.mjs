import { existsSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const DEFAULT_PHP_CLI = "/usr/bin/php8.5-cli";
const dashboardDirectory = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const envPath = join(dashboardDirectory, "keys", ".env.do_not_commit");
const phpFiles = [
  { localPath: "server.php", remoteName: "server.php" },
  { localPath: "dashboard.php", remoteName: "dashboard.php" },
  { localPath: "analytics-common.php", remoteName: "analytics-common.php" },
  { localPath: "analytics-reporting.php", remoteName: "analytics-reporting.php" },
  { localPath: "database-migration.php", remoteName: "database-migration.php" },
  { localPath: "scripts/prepare-database-v7.2.php", remoteName: "prepare-database-v7.2.php" },
  { localPath: "scripts/import-database-v7.2.php", remoteName: "import-database-v7.2.php" },
  { localPath: "scripts/migrate-remaining-databases-v7.2.php", remoteName: "migrate-remaining-databases-v7.2.php" },
];

function loadDeploymentEnvironment() {
  if (!existsSync(envPath)) {
    return false;
  }

  for (const line of readFileSync(envPath, "utf8").split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) continue;

    const separatorIndex = trimmed.indexOf("=");
    if (separatorIndex <= 0) continue;

    const key = trimmed.slice(0, separatorIndex).trim();
    let value = trimmed.slice(separatorIndex + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    if (value) process.env[key] = value;
  }
  return true;
}

function shellQuote(value) {
  return `'${value.replace(/'/g, "'\\''")}'`;
}

async function run(executable, args, label, env) {
  console.log(`➡️ ${label}`);
  const processHandle = Bun.spawn([executable, ...args], {
    stdin: "inherit",
    stdout: "inherit",
    stderr: "inherit",
    ...(env ? { env } : {}),
  });
  const exitCode = await processHandle.exited;
  if (exitCode !== 0) {
    throw new Error(`${label} a échoué (code ${exitCode}).`);
  }
}

async function main() {
  const hasDeploymentEnvironment = loadDeploymentEnvironment();
  const hasSshKey = hasDeploymentEnvironment &&
    process.env.IONOS_SSH_USER &&
    process.env.IONOS_SSH_HOST &&
    process.env.IONOS_SSH_KEY &&
    existsSync(process.env.IONOS_SSH_KEY);
  if (!hasSshKey) {
    console.log("Clé SSH IONOS absente ou configuration incomplète ; lancement de l'initialisation en simulation.");
    await run(
      process.execPath,
      [join(dashboardDirectory, "scripts", "deploy-follow.mjs")],
      "Initialisation SSH IONOS sans déploiement",
      {
        ...process.env,
        IONOS_DEPLOY_DRY_RUN: "1",
        IONOS_DEPLOY_SETUP_ONLY: "1",
      },
    );
    if (!loadDeploymentEnvironment()) {
      throw new Error(`Configuration IONOS introuvable après initialisation : ${envPath}`);
    }
  }

  const sshUser = process.env.IONOS_SSH_USER;
  const sshHost = process.env.IONOS_SSH_HOST;
  const sshKey = process.env.IONOS_SSH_KEY;
  const phpCli = process.env.IONOS_PHP_CLI || DEFAULT_PHP_CLI;

  if (!sshUser || !sshHost || !sshKey || !existsSync(sshKey)) {
    throw new Error('Configuration SSH IONOS incomplète dans keys/.env.do_not_commit.');
  }

  const sshExecutable = process.env.IONOS_SSH_EXE || Bun.which("ssh");
  const scpExecutable = process.env.IONOS_SCP_EXE || Bun.which("scp");
  if (!sshExecutable || !scpExecutable) {
    throw new Error("ssh ou scp est introuvable sur le système local.");
  }

  const localFiles = phpFiles.map((file) => join(dashboardDirectory, file.localPath));
  for (const file of localFiles) {
    if (!existsSync(file)) {
      throw new Error(`Fichier PHP local introuvable : ${file}`);
    }
  }

  const destination = `${sshUser}@${sshHost}`;
  const connectionArgs = ["-i", sshKey, "-o", "BatchMode=yes"];
  const stageName = `.php-lint-${Date.now()}-${process.pid}`;
  const remoteStage = `~/${stageName}`;
  const createStageCommand = `set -eu\nmkdir -m 700 -- "$HOME/${stageName}"`;
  const phpCliAssignment = `phpCli=${shellQuote(phpCli)}`;
  const lintCommand = [
    "set -eu",
    `stage="$HOME/${stageName}"`,
    phpCliAssignment,
    ...phpFiles.map((file) => `"$phpCli" -l "$stage/${file.remoteName}"`),
  ].join("\n");
  const cleanupCommand = [
    "set -eu",
    `stage="$HOME/${stageName}"`,
    `rm -f -- ${phpFiles.map((file) => `"$stage/${file.remoteName}"`).join(" ")}`,
    'rmdir -- "$stage"',
  ].join("\n");

  let stageCreated = false;
  let failure = null;
  try {
    await run(sshExecutable, [...connectionArgs, destination, createStageCommand], "Création du répertoire temporaire privé sur IONOS");
    stageCreated = true;
    await run(
      scpExecutable,
      ["-r", ...connectionArgs, ...localFiles, `${destination}:${remoteStage}/`],
      "Transfert temporaire des fichiers PHP",
    );
    await run(sshExecutable, [...connectionArgs, destination, lintCommand], "Vérification PHP sur IONOS");
  } catch (error) {
    failure = error;
  }

  if (stageCreated) {
    try {
      await run(sshExecutable, [...connectionArgs, destination, cleanupCommand], "Nettoyage du répertoire temporaire IONOS");
    } catch (cleanupError) {
      console.error(cleanupError instanceof Error ? cleanupError.message : cleanupError);
      if (!failure) failure = cleanupError;
    }
  }

  if (failure) throw failure;
  console.log("✅ Tous les fichiers PHP passent php -l sur IONOS.");
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : error);
  process.exitCode = 1;
});

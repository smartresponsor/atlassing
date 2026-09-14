#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { execFileSync } from "node:child_process";
import { pathToFileURL } from "node:url";

const args = Object.fromEntries(process.argv.slice(2).map((v) => {
  const i = v.indexOf("=");
  return i > 2 && v.startsWith("--") ? [v.slice(2, i), v.slice(i + 1)] : [v.replace(/^--/, ""), "1"];
}));

function inheritWindowsAwsEnvironment() {
  for (const name of ["AWS_PROFILE", "AWS_DEFAULT_PROFILE", "AWS_REGION", "AWS_DEFAULT_REGION"]) {
    if (process.env[name]?.trim()) continue;
    for (const scope of ["User", "Machine"]) {
      const value = execFileSync("powershell.exe", ["-NoProfile", "-Command", `[Environment]::GetEnvironmentVariable('${name}','${scope}')`], {
        encoding: "utf8", windowsHide: true, stdio: ["ignore", "pipe", "ignore"],
      }).trim();
      if (value) { process.env[name] = value; break; }
    }
  }
}

function bearer() {
  const direct = process.env.CONSOLE_MCP_BEARER_TOKEN?.trim();
  if (!direct) throw new Error("CONSOLE_MCP_BEARER_TOKEN is required in the process environment.");
  return { value: direct, source: process.env.CONSOLE_MCP_BEARER_TOKEN_SOURCE || "environment" };
}

function payload(result) {
  if (result?.structuredContent && typeof result.structuredContent === "object" && !Array.isArray(result.structuredContent)) return result.structuredContent;
  return JSON.parse(result?.content?.find?.((x) => x?.type === "text")?.text ?? result?.content?.[0]?.text ?? "");
}

function verdict(text) {
  const s = String(text ?? "").trim();
  try { return JSON.parse(s); } catch {}
  const fenced = s.match(/```(?:json)?\s*([\s\S]*?)```/i);
  if (fenced) { try { return JSON.parse(fenced[1].trim()); } catch {} }
  const a = s.indexOf("{"), b = s.lastIndexOf("}");
  if (a >= 0 && b > a) return JSON.parse(s.slice(a, b + 1));
  throw new Error("Assistant answer did not contain JSON.");
}

async function main() {
  const component = args.component, workspace = args.workspace, promptFile = args["prompt-file"];
  const fallbackConsoleRoot = workspace ? path.join(path.dirname(workspace), "mcp", "console-mcp") : "D:\\PhpstormProjects\\www\\mcp\\console-mcp";
  const consoleRoot = path.resolve(args["console-root"] || fallbackConsoleRoot);
  const endpoint = new URL(process.env.CONSOLE_MCP_ENDPOINT || "http://127.0.0.1:3334/mcp");
  const secret = bearer();
  if (!args.preflight && (!component || !workspace || !promptFile)) throw new Error("--component, --workspace and --prompt-file are required.");
  if (!args.preflight && !fs.existsSync(promptFile)) throw new Error(`Prompt file not found: ${promptFile}`);
  const clientUrl = pathToFileURL(path.join(consoleRoot, "node_modules", "@modelcontextprotocol", "sdk", "dist", "esm", "client", "index.js")).href;
  const transportUrl = pathToFileURL(path.join(consoleRoot, "node_modules", "@modelcontextprotocol", "sdk", "dist", "esm", "client", "streamableHttp.js")).href;
  const [{ Client }, { StreamableHTTPClientTransport }] = await Promise.all([import(clientUrl), import(transportUrl)]);
  const transport = new StreamableHTTPClientTransport(endpoint, { requestInit: { headers: { Authorization: `Bearer ${secret.value}` } } });
  const client = new Client({ name: "atlassing-quality-atlas", version: "1.0.0" });
  await client.connect(transport);
  try {
    if (args.preflight) {
      const described = payload(await client.callTool({ name: "console.read_.system.console.describe", arguments: {} }));
      process.stdout.write(JSON.stringify({ ok: described?.server_name === "console-mcp", status: "CONSOLE_MCP_RAW_SCORING_PREFLIGHT", secret_source: secret.source, endpoint: endpoint.origin }) + "\n");
      return;
    }
    const rawCommand = [
      `Quality Atlas scoring task for component ${component}.`,
      `Target workspace: ${workspace}`,
      `Read the complete scoring specification from this local file using Console MCP repository/file capabilities: ${promptFile}`,
      "Follow it exactly. Assessment only: do not modify the target repository, commit, or push.",
      "Return only the strict JSON verdict requested by the scoring specification.",
    ].join("\n");
    const started = payload(await client.callTool({ name: "console.write.browser.chatgpt.chat.create.send", arguments: {
      prompt: rawCommand,
      component,
      taskId: `quality-atlas-${component}`,
      promptId: "quality-atlas-score",
      allowOverwrite: false,
      allowGuestRootSession: false,
      activate: true,
      confirmSend: true,
      timeoutMs: 30000,
    }}));
    const chatId = started?.chat_id ?? started?.chatId;
    if (started?.ok !== true || !chatId) throw new Error(`Console MCP launch failed: ${JSON.stringify(started).slice(0, 8000)}`);
    const settled = payload(await client.callTool({ name: "console.read_.browser.chatgpt.answer.settle", arguments: {
      preferredChatId: chatId, requireChatId: true, maxMessages: 30, readinessProfile: "long_run",
      maxWaitMs: 600000, observationBudgetMs: 60000, pollMs: 2000, minStableSamples: 2,
      requireComposerSendMode: false, timeoutMs: 10000,
    }}));
    if (settled?.ok !== true || settled?.settled !== true) throw new Error(`Console MCP answer did not settle: ${JSON.stringify(settled).slice(0, 8000)}`);
    const latest = settled.latest_assistant ?? settled.latestAssistant;
    process.stdout.write(JSON.stringify({
      ok: true, transport: "console-mcp-cmcp-go-raw", component,
      verdict: verdict(latest?.text ?? settled.assistant_text ?? settled.text),
      lifecycle: { launch_status: started.status ?? null, settle_status: settled.status ?? null, secret_source: secret.source },
    }) + "\n");
  } finally {
    await transport.close();
    await client.close?.();
  }
}

main().catch((error) => {
  process.stderr.write((error instanceof Error ? error.stack ?? error.message : String(error)) + "\n");
  process.exitCode = 1;
});

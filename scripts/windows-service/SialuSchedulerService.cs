// SialuScheduler: servicio de Windows que ejecuta "php artisan schedule:run" al inicio de cada minuto.
// Qué se programa y con qué frecuencia lo decide Laravel (routes/console.php); este servicio solo es el reloj.
// Compilar e instalar con scripts/windows-service/install-scheduler-service.ps1.
//
// Argumentos: --php <php.exe> --app <carpeta SIALU-api> [--console] [--once]
//   --console  ejecuta en primer plano (pruebas) hasta Ctrl+C.
//   --once     una sola ejecución de schedule:run; el código de salida indica error (1) o éxito (0).
// Registro: <app>\storage\logs\scheduler-service.log (rota a .1 al superar 5 MB) y, para avisos y errores,
// el registro de eventos Application con origen "SialuScheduler" (lo crea el instalador).
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;
using System.ServiceProcess;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;

namespace Sialu.Scheduler
{
    public sealed class SchedulerService : ServiceBase
    {
        public const string Name = "SialuScheduler";
        const long MaxLogBytes = 5L * 1024 * 1024;
        static readonly TimeSpan MaxRunTime = TimeSpan.FromMinutes(30);
        static readonly TimeSpan StopGrace = TimeSpan.FromSeconds(90);
        // The wait is cut into slices and re-measured against the wall clock: a relative wait does not advance
        // while the machine sleeps, and the clock may jump (time sync after resume).
        static readonly TimeSpan WaitSlice = TimeSpan.FromSeconds(5);
        // How long to wait for stdout/stderr EOF after php.exe exits (a grandchild may keep the pipes open).
        static readonly TimeSpan OutputDrain = TimeSpan.FromSeconds(30);
        // Watchdog: if the loop completes no cycle for this long, log it and end the process so the service
        // recovery actions (sc failure: restart) start a fresh one. Normal cycles take at most a few seconds;
        // the longest legitimate pause is OutputDrain plus a taskkill (15 s).
        static readonly TimeSpan LoopStall = TimeSpan.FromMinutes(3);
        static readonly TimeSpan WatchdogPeriod = TimeSpan.FromSeconds(30);
        // Summary line so a quiet log ("No scheduled commands" is not logged) can be told apart from a hung loop.
        static readonly TimeSpan HeartbeatEvery = TimeSpan.FromMinutes(15);
        // SIALU-SCHEDULE-FAIL: marca de routes/console.php; FAIL: por si Laravel corrige su salida.
        static readonly Regex Failed = new Regex(@"SIALU-SCHEDULE-FAIL|\bFAIL\b");

        readonly string php, app, logFile;
        readonly bool console;
        readonly object logLock = new object();
        readonly List<Job> running = new List<Job>();
        readonly ManualResetEvent stopping = new ManualResetEvent(false);
        readonly AutoResetEvent resumed = new AutoResetEvent(false);
        Thread loop;
        long suspendedAtTicks; // 0 = not suspended
        bool? keepingAwake;
        Timer watchdog;
        long lastCycleTicks;   // UTC ticks of the last completed loop cycle (written by the loop, read by the watchdog)
        long lastWatchdogTicks;
        string loopStep = "inicio"; // what the loop is doing, for the watchdog's message
        int started, idle, worked, failed; // since the last heartbeat
        DateTime nextHeartbeat;

        [Flags] enum ExecutionState : uint { SystemRequired = 0x00000001, Continuous = 0x80000000 }
        [DllImport("kernel32.dll")] static extern ExecutionState SetThreadExecutionState(ExecutionState state);
        [StructLayout(LayoutKind.Sequential)]
        struct SystemPowerStatus { public byte ACLineStatus, BatteryFlag, BatteryLifePercent, SystemStatusFlag; public int BatteryLifeTime, BatteryFullLifeTime; }
        [DllImport("kernel32.dll")] static extern bool GetSystemPowerStatus(out SystemPowerStatus status);

        sealed class Job
        {
            public Process Process;
            public DateTime Started;
            public StringBuilder Output = new StringBuilder();
            // Set when the async readers reach EOF (e.Data == null) on stdout / stderr.
            public ManualResetEvent OutEof = new ManualResetEvent(false), ErrEof = new ManualResetEvent(false);
        }

        public SchedulerService(string php, string app, bool console)
        {
            ServiceName = Name;
            CanStop = true;
            CanShutdown = true;
            CanHandlePowerEvent = true;
            this.php = php;
            this.app = app;
            this.console = console;
            logFile = Path.Combine(app, @"storage\logs\scheduler-service.log");
        }

        public static int Main(string[] args)
        {
            string php = null, app = null;
            bool console = false, once = false;
            for (int i = 0; i < args.Length; i++)
            {
                switch (args[i])
                {
                    case "--php": php = i + 1 < args.Length ? args[++i] : null; break;
                    case "--app": app = i + 1 < args.Length ? args[++i] : null; break;
                    case "--console": console = true; break;
                    case "--once": once = true; console = true; break;
                    default: Console.Error.WriteLine("Argumento desconocido: " + args[i]); return 2;
                }
            }
            if (php == null || app == null)
            {
                Console.Error.WriteLine("Uso: SialuScheduler.exe --php <php.exe> --app <SIALU-api> [--console|--once]");
                return 2;
            }
            var service = new SchedulerService(php, Path.GetFullPath(app), console);
            if (!console) { ServiceBase.Run(service); return 0; }

            try { service.Validate(); }
            catch (Exception e) { service.Log("ERROR", e.Message, true); return 1; }
            if (once) return service.RunOnce() ? 0 : 1;

            Console.CancelKeyPress += (s, e) => { e.Cancel = true; service.stopping.Set(); };
            service.Log("INFO", "Inicio en consola. PHP: " + php + "; app: " + service.app, false);
            service.StartWatchdog();
            service.Loop();
            service.WaitForRuns();
            service.Log("INFO", "Detenido.", false);
            return 0;
        }

        protected override void OnStart(string[] args)
        {
            try { Validate(); }
            catch (Exception e) { Log("ERROR", "No se inicia: " + e.Message, true); throw; }
            Log("INFO", "Servicio iniciado. PHP: " + php + "; app: " + app + "; cuenta: " + Environment.UserDomainName + "\\" + Environment.UserName
                + "; ejecutable compilado " + File.GetLastWriteTime(typeof(SchedulerService).Assembly.Location).ToString("yyyy-MM-dd HH:mm:ss"), false);
            loop = new Thread(Loop) { IsBackground = true, Name = "scheduler-loop" };
            loop.Start();
            StartWatchdog();
        }

        void StartWatchdog()
        {
            Interlocked.Exchange(ref lastCycleTicks, DateTime.UtcNow.Ticks);
            Interlocked.Exchange(ref lastWatchdogTicks, DateTime.UtcNow.Ticks);
            watchdog = new Timer(Watchdog, null, WatchdogPeriod, WatchdogPeriod);
        }

        // Runs on a timer thread, independent of the loop. A stalled loop is logged (file and event log) and,
        // as a service, the process exits with code 3: SCM sees an unexpected termination and restarts it.
        void Watchdog(object state)
        {
            try
            {
                var now = DateTime.UtcNow;
                var sinceLastCheck = now - new DateTime(Interlocked.Exchange(ref lastWatchdogTicks, now.Ticks), DateTimeKind.Utc);
                if (stopping.WaitOne(0)) return;
                // The timer itself was late (sleep, hibernation, clock jump forward) or the machine is suspended:
                // the loop could not run either, so restart the measurement instead of blaming it.
                if (sinceLastCheck > WatchdogPeriod + WatchdogPeriod || Interlocked.Read(ref suspendedAtTicks) != 0)
                {
                    Interlocked.Exchange(ref lastCycleTicks, now.Ticks);
                    return;
                }
                var last = new DateTime(Interlocked.Read(ref lastCycleTicks), DateTimeKind.Utc);
                var stalled = now - last;
                if (stalled < LoopStall) return;
                int pending;
                lock (running) pending = running.Count;
                Log("ERROR", "El bucle del programador no completa ciclos desde hace " + (int)stalled.TotalSeconds + " s (último ciclo "
                    + last.ToLocalTime().ToString("yyyy-MM-dd HH:mm:ss") + "; paso: " + loopStep + "; hilo: "
                    + (loop != null ? loop.ThreadState.ToString() : "-") + "; schedule:run en seguimiento: " + pending + ")."
                    + (console ? "" : " Se termina el proceso para que el servicio se reinicie (acciones de recuperación)."), true);
                if (console) { Interlocked.Exchange(ref lastCycleTicks, now.Ticks); return; }
                lock (running) foreach (var run in running) { try { if (!run.Process.HasExited) KillTree(run.Process); } catch { } }
                Environment.Exit(3);
            }
            catch (Exception e) { Log("ERROR", "Error en el vigilante del bucle: " + e.Message, true); }
        }

        void Cycle(string step)
        {
            loopStep = step;
            Interlocked.Exchange(ref lastCycleTicks, DateTime.UtcNow.Ticks);
        }

        void Heartbeat()
        {
            if (DateTime.Now < nextHeartbeat) return;
            int pending;
            lock (running) pending = running.Count;
            Log("INFO", "Activo. Últimos " + (int)HeartbeatEvery.TotalMinutes + " min: " + started + " schedule:run iniciados, "
                + worked + " con tareas, " + idle + " sin tareas pendientes, " + failed + " con error; en curso: " + pending + ".", false);
            started = idle = worked = failed = 0;
            nextHeartbeat = DateTime.Now + HeartbeatEvery;
        }

        protected override void OnStop() { Shutdown("Servicio detenido."); }

        protected override void OnShutdown() { Shutdown("Apagado del sistema."); }

        // A suspended machine runs nothing: the service stays "Running" but schedule:run cannot run.
        // Log it so a gap in the snapshots can be told apart from a hung service.
        protected override bool OnPowerEvent(PowerBroadcastStatus status)
        {
            if (status == PowerBroadcastStatus.Suspend)
            {
                Interlocked.Exchange(ref suspendedAtTicks, DateTime.Now.Ticks);
                Log("WARN", "El equipo entra en suspensión; schedule:run no se ejecutará hasta que se reanude.", true);
            }
            else if (status == PowerBroadcastStatus.ResumeAutomatic || status == PowerBroadcastStatus.ResumeSuspend)
            {
                long since = Interlocked.Exchange(ref suspendedAtTicks, 0);
                if (since != 0)
                    Log("WARN", "El equipo se reanudó tras " + (DateTime.Now - new DateTime(since)).ToString(@"d\.hh\:mm\:ss")
                        + " suspendido (desde " + new DateTime(since).ToString("yyyy-MM-dd HH:mm:ss") + "); se reanuda schedule:run.", true);
                resumed.Set();
            }
            return true;
        }

        // Keeps the machine from sleeping for inactivity while on AC power (the plan's idle timer would otherwise
        // stop the scheduler). On battery it lets it sleep. Closing the lid or pressing the power button still suspends.
        // Execution state is per thread: this is called only from the loop thread.
        void KeepAwake()
        {
            SystemPowerStatus power;
            bool onAc = GetSystemPowerStatus(out power) && power.ACLineStatus == 1;
            if (keepingAwake == onAc) return;
            SetThreadExecutionState(onAc ? ExecutionState.Continuous | ExecutionState.SystemRequired : ExecutionState.Continuous);
            Log("INFO", onAc ? "Con corriente alterna: se evita la suspensión por inactividad." : "Con batería: se permite la suspensión por inactividad.", false);
            keepingAwake = onAc;
        }

        static DateTime NextMinute(DateTime now)
        {
            return new DateTime(now.Year, now.Month, now.Day, now.Hour, now.Minute, 0, now.Kind).AddMinutes(1);
        }

        void Shutdown(string reason)
        {
            RequestAdditionalTime((int)StopGrace.TotalMilliseconds + 15000);
            stopping.Set();
            if (watchdog != null) watchdog.Dispose();
            if (loop != null) loop.Join(TimeSpan.FromSeconds(50)); // a cycle may be inside Finish (OutputDrain)
            WaitForRuns();
            Log("INFO", reason, false);
        }

        void Validate()
        {
            if (!File.Exists(php)) throw new FileNotFoundException("No existe PHP: " + php);
            if (!File.Exists(Path.Combine(app, "artisan"))) throw new FileNotFoundException("No existe artisan en " + app);
            Directory.CreateDirectory(Path.GetDirectoryName(logFile));
        }

        // Starts schedule:run at second 0 of every minute. Runs may overlap: each job's withoutOverlapping decides.
        // Waits in short slices against the wall clock, reaping finished runs meanwhile, so neither sleep nor clock
        // changes leave it waiting on a stale deadline.
        // Every call made from here is bounded (no infinite waits): see Finish and KillTree. An exception in a
        // cycle is logged and the loop goes on; a hang is caught by the watchdog.
        void Loop()
        {
            var waits = new WaitHandle[] { stopping, resumed };
            var next = NextMinute(DateTime.Now);
            nextHeartbeat = DateTime.Now + HeartbeatEvery;
            while (true)
            {
                try
                {
                    Cycle("espera");
                    if (!console) KeepAwake();
                    var wait = next - DateTime.Now;
                    if (wait > TimeSpan.Zero)
                    {
                        if (wait > TimeSpan.FromSeconds(61)) next = NextMinute(DateTime.Now); // clock moved back
                        else
                        {
                            int signaled = WaitHandle.WaitAny(waits, wait < WaitSlice ? wait : WaitSlice);
                            if (signaled == 0) return;
                            if (signaled == 1) next = NextMinute(DateTime.Now); // resumed: do not run the missed minute late
                            Cycle("revisión de ejecuciones");
                            Reap();
                        }
                        continue;
                    }
                    next = NextMinute(DateTime.Now);
                    Cycle("inicio de schedule:run");
                    try { Start(); started++; }
                    catch (Exception e) { failed++; Log("ERROR", "No fue posible iniciar schedule:run: " + e.Message, true); }
                    Cycle("revisión de ejecuciones");
                    Reap();
                    Heartbeat();
                }
                catch (Exception e)
                {
                    Log("ERROR", "Error en el bucle del programador (" + loopStep + "): " + e, true);
                    if (stopping.WaitOne(TimeSpan.FromSeconds(5))) return;
                }
            }
        }

        bool RunOnce()
        {
            var run = Start();
            while (!run.Process.WaitForExit(1000)) // WaitForExit(int) does not wait for pipe EOF; Finish does, bounded
            {
                if (DateTime.Now - run.Started > MaxRunTime)
                {
                    Log("ERROR", "schedule:run (PID " + run.Process.Id + ") superó " + MaxRunTime.TotalMinutes + " minutos; se termina.", true);
                    KillTree(run.Process);
                    run.Process.WaitForExit(15000);
                    break;
                }
            }
            lock (running) running.Remove(run);
            return Finish(run);
        }

        Job Start()
        {
            var info = new ProcessStartInfo(php, "artisan schedule:run --no-interaction --no-ansi")
            {
                WorkingDirectory = app,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                StandardOutputEncoding = Encoding.UTF8,
                StandardErrorEncoding = Encoding.UTF8,
            };
            var run = new Job { Started = DateTime.Now };
            run.Process = new Process { StartInfo = info, EnableRaisingEvents = true };
            run.Process.OutputDataReceived += (s, e) => { if (e.Data == null) run.OutEof.Set(); else lock (run.Output) run.Output.AppendLine(e.Data); };
            run.Process.ErrorDataReceived += (s, e) => { if (e.Data == null) run.ErrEof.Set(); else lock (run.Output) run.Output.AppendLine(e.Data); };
            run.Process.Start();
            run.Process.BeginOutputReadLine();
            run.Process.BeginErrorReadLine();
            lock (running) running.Add(run);
            return run;
        }

        // Logs finished runs; kills any run stuck beyond MaxRunTime so it cannot pile up.
        void Reap()
        {
            List<Job> done = new List<Job>();
            lock (running)
            {
                foreach (var run in running)
                {
                    if (run.Process.HasExited) done.Add(run);
                    else if (DateTime.Now - run.Started > MaxRunTime)
                    {
                        Log("ERROR", "schedule:run (PID " + run.Process.Id + ") superó " + MaxRunTime.TotalMinutes + " minutos; se termina.", true);
                        KillTree(run.Process);
                    }
                }
                foreach (var run in done) running.Remove(run);
            }
            foreach (var run in done) Finish(run);
        }

        bool Finish(Job run)
        {
            // Process.WaitForExit() without a timeout also waits for EOF on the redirected pipes; php's children
            // inherit those pipes (proc_open), so one that outlives php.exe would block the loop indefinitely
            // (the installed version before 2026-11-10 did exactly that). Wait for EOF ourselves, bounded.
            var process = run.Process;
            var deadline = DateTime.UtcNow + OutputDrain;
            bool drained = run.OutEof.WaitOne(Remaining(deadline)) & run.ErrEof.WaitOne(Remaining(deadline));
            string output;
            lock (run.Output) output = run.Output.ToString().Trim();
            int code = process.ExitCode;
            if (drained)
            {
                process.Dispose();
                run.OutEof.Close();
                run.ErrEof.Close();
            }
            // Not disposed: the pending reads still own the pipes; they finish (or fail quietly) when the holder exits.
            else Log("WARN", "schedule:run (PID " + process.Id + ") terminó (código " + code + "), pero un proceso hijo mantiene abierta su salida tras "
                + (int)OutputDrain.TotalSeconds + " s; se continúa sin esperarla.", true);
            bool isFailed = code != 0 || Failed.IsMatch(output);
            bool isIdle = output.IndexOf("No scheduled commands are ready to run", StringComparison.OrdinalIgnoreCase) >= 0;
            if (isFailed) failed++; else if (isIdle) idle++; else worked++;
            if (isFailed)
                Log("ERROR", "schedule:run con error (código " + code + "). Detalle en storage/logs/laravel.log y orion-status-schedule.log." + Environment.NewLine + output, true);
            else if (output.Length > 0 && !isIdle)
                Log("INFO", output, false);
            return !isFailed;
        }

        static TimeSpan Remaining(DateTime deadlineUtc)
        {
            var left = deadlineUtc - DateTime.UtcNow;
            return left > TimeSpan.Zero ? left : TimeSpan.Zero;
        }

        void WaitForRuns()
        {
            var deadline = DateTime.Now + StopGrace;
            while (true)
            {
                Reap();
                lock (running) if (running.Count == 0) return;
                if (DateTime.Now > deadline) break;
                Thread.Sleep(500);
            }
            lock (running)
            {
                foreach (var run in running)
                {
                    Log("WARN", "Se termina schedule:run (PID " + run.Process.Id + ") al detener; su bloqueo caduca según ORION_STATUS_SCHEDULE_LOCK_MINUTES.", true);
                    KillTree(run.Process);
                }
                running.Clear();
            }
        }

        static void KillTree(Process process)
        {
            try
            {
                using (var kill = Process.Start(new ProcessStartInfo("taskkill.exe", "/T /F /PID " + process.Id) { UseShellExecute = false, CreateNoWindow = true }))
                    kill.WaitForExit(15000);
            }
            catch { try { process.Kill(); } catch { } }
        }

        void Log(string level, string message, bool eventLog)
        {
            string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " [" + level + "] " + message;
            lock (logLock)
            {
                try
                {
                    var info = new FileInfo(logFile);
                    if (info.Exists && info.Length > MaxLogBytes)
                    {
                        string old = logFile + ".1";
                        if (File.Exists(old)) File.Delete(old);
                        File.Move(logFile, old);
                    }
                    File.AppendAllText(logFile, line + Environment.NewLine, new UTF8Encoding(false));
                }
                catch (Exception e) { eventLog = true; message += " (no se pudo escribir " + logFile + ": " + e.Message + ")"; }
            }
            if (console) Console.WriteLine(line);
            if (!eventLog || level == "INFO") return;
            try
            {
                if (EventLog.SourceExists(Name))
                    EventLog.WriteEntry(Name, message.Length > 30000 ? message.Substring(0, 30000) : message,
                        level == "ERROR" ? EventLogEntryType.Error : EventLogEntryType.Warning);
            }
            catch { }
        }
    }
}

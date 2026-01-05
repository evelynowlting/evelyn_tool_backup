<?php

namespace App\Console\Commands\EVelyn;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeSrcEventCommand extends Command
{
    protected $signature = 'make:src-event
                            {name}
                            {--path=Domain/CRB/Events: The path to create the event in}
                            {--argType=array: The type of the argument (string, int,array etc.)}
                            {--argName=Transaction: The name of the argument}';

    protected $description = 'Create a new event under src/Domain/CRB/Events/Wire';

    public function handle(): int
    {
        $name = $this->argument('name');
        $baseNamespace = $this->option('path');  // e.g., Domain or Infrastructure
        // $argumentType = $this->option('argType') ?? 'string';
        // $argumentName = $this->option('argName') ?? 'Transaction';

        $className = Str::studly($name);

        $basePath = base_path('src');
        $folderPath = "{$basePath}/{$baseNamespace}";
        $filePath = "{$folderPath}/{$className}.php";

        if (File::exists($filePath)) {
            $this->warn("File Already exists: {$folderPath}");

            return 0;
        } else {
            if (!File::exists($folderPath)) {
                $this->warn("Folder does not exist: {$folderPath}");
                File::makeDirectory($folderPath, 0755, true);
                File::ensureDirectoryExists($basePath);
                $this->info("Create folder: {$folderPath}");
                $this->info("Please continue to create file: {$name} under {$folderPath}");
            } else {
                $this->warn("Folder Already exists: {$folderPath}");
                $this->info("Create file: {$name} under {$folderPath}");

                $baseNamespace = str_replace('/', '\\', $baseNamespace);

                $stub = <<<PHP
                <?php

                namespace {$baseNamespace};

                use Illuminate\Foundation\Events\Dispatchable;
                use Illuminate\Queue\SerializesModels;

                class {$className}
                {
                    use Dispatchable, SerializesModels;

                    public function __construct(public CrbSubledger \$subledgerInfo)
                    {
                    }

                    public function getSubledgerInfo(): CrbSubledger
                    {
                        return \$this->subledgerInfo;
                    }

                }
                PHP;

                $baseNamespace = str_replace('\\', '/', $baseNamespace);

                File::put($filePath, $stub);

                $this->info("✅ Event created: {$filePath}.php");
            }
        }

        return 0;
    }
}

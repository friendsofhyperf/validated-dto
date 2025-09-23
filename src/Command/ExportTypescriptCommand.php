<?php

declare(strict_types=1);
/**
 * This file is part of friendsofhyperf/components.
 *
 * @link     https://github.com/friendsofhyperf/components
 * @document https://github.com/friendsofhyperf/components/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */

namespace FriendsOfHyperf\ValidatedDTO\Command;

use FriendsOfHyperf\ValidatedDTO\Export\TypescriptExporter;
use Hyperf\CodeParser\Project;
use Hyperf\Contract\ConfigInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportTypescriptCommand extends SymfonyCommand
{
    protected InputInterface $input;

    protected OutputInterface $output;

    public function __construct(
        protected ConfigInterface $config,
        protected TypescriptExporter $exporter
    ) {
        parent::__construct('export:typescript');
    }

    public function configure()
    {
        foreach ($this->getArguments() as $argument) {
            $this->addArgument(...$argument);
        }

        foreach ($this->getOptions() as $option) {
            $this->addOption(...$option);
        }

        $this->setDescription('Export DTO classes to TypeScript interface definitions.');
        $this->setAliases(['export:ts']);
    }

    /**
     * Execute the console command.
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $dtoPath = $input->getArgument('dto-path') ?: $this->getDefaultDtoPath();
        $outputPath = $input->getOption('output') ?: $this->getDefaultOutputPath();
        $filename = $input->getOption('filename') ?: $this->config->get('dto.typescript.filename', 'dtos.ts');

        if (! is_dir($dtoPath)) {
            $output->writeln("<error>DTO path '{$dtoPath}' does not exist.</error>");
            return 1;
        }

        try {
            $result = $this->exporter->export($dtoPath, $outputPath, $filename);
            
            if ($result['count'] === 0) {
                $output->writeln('<comment>No DTO classes found to export.</comment>');
                return 0;
            }

            $output->writeln("<info>Successfully exported {$result['count']} DTO classes to {$result['file']}</info>");

            if (! empty($result['skipped'])) {
                $output->writeln('<comment>Skipped classes:</comment>');
                foreach ($result['skipped'] as $skipped) {
                    $output->writeln("  - {$skipped}");
                }
            }

            return 0;
        } catch (\Exception $e) {
            $output->writeln("<error>Export failed: {$e->getMessage()}</error>");
            return 1;
        }
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['dto-path', InputArgument::OPTIONAL, 'The path to scan for DTO classes'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['output', 'o', InputOption::VALUE_OPTIONAL, 'Output directory path'],
            ['filename', 'f', InputOption::VALUE_OPTIONAL, 'Output filename (default: dtos.ts)'],
        ];
    }

    protected function getDefaultDtoPath(): string
    {
        $namespace = $this->config->get('dto.namespace', 'App\\DTO');
        $project = new Project();
        
        // Convert namespace to path
        $path = str_replace('\\', '/', $namespace);
        $path = str_replace('App/', 'app/', $path);
        
        return BASE_PATH . '/' . $path;
    }

    protected function getDefaultOutputPath(): string
    {
        return $this->config->get('dto.typescript.output_path', BASE_PATH . '/resources/typescript');
    }
}
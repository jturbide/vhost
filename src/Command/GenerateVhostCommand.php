<?php

namespace Vhost\Command;

use Vhost\Generator\VhostGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateVhostCommand extends Command
{
    public function __construct()
    {
        parent::__construct('generate:vhost');
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('Generate vhost configurations for Apache and Nginx.')
            
            ->addOption('os', null, InputOption::VALUE_OPTIONAL, 'Override OS detection (windows/linux).')
            ->addOption('config', null, InputOption::VALUE_OPTIONAL, 'Path to the config.yaml file.')
            ->addOption('sites', null, InputOption::VALUE_OPTIONAL, 'Path to the sites.yaml file.')
            
            ->addOption('force', 'f', null, 'Overwrite existing files.')
            ->addOption('no-backup', null, null, 'Disable backups.')
            ->addOption('restart', 'r', null, 'Restart services after generation.')
            
            ->addOption('restart-php', null, null, 'Restart detected PHP-FPM services (Linux only).')
            ->addOption('clear-opcache', null, null, 'Clear OPCache after vhost generation.')
            ->addOption('clear-apcu', null, null, 'Clear APCu cache after vhost generation.')
            ->addOption('clear-sessions', null, null, 'Clear PHP sessions after vhost generation.');

    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $generator = new VhostGenerator(
            configFile: $input->getOption('config'),
            sitesFile: $input->getOption('sites'),
            os: $input->getOption('os') ?: PHP_OS_FAMILY,
            force: $input->getOption('force'),
            backup: !$input->getOption('no-backup'),
            verbose: $output->isVerbose(),
            restartApacheNginx: $input->getOption('restart'),
            restartPhpFpm: $input->getOption('restart-php'),
            clearOpcache: $input->getOption('clear-opcache'),
            clearApcu: $input->getOption('clear-apcu'),
            clearSessions: $input->getOption('clear-sessions'),
            output: $output
        );
        
        
        $generator->run();
        
        $output->writeln('<info>✅ Vhost configuration generated successfully!</info>');
        
        return Command::SUCCESS;
    }
}

<?php

namespace Anatolv\Bitrix\Import\Command;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use InvalidArgumentException;

class AvgDatabaseImportCommand extends Command
{
    protected static $defaultName = 'avg:database-import|avg:database:import';
    protected string $destPathEntity = '/Entity/';
    protected string $destPathRepository = '/Repository/';
    protected string $namespace = 'App';
    protected array $tables;
    protected object $_entityManager;
    protected object $_output;

    /**
     * Command constructor.
     *
     * @param EntityManagerInterface $entityManager to access Doctrine
     *
     * @return void
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->_entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('avg:database-import')
            ->setAliases(['avg:database:import'])
            ->setDescription('Convert mapping information ')
            ->addOption('regenerate', null, InputOption::VALUE_OPTIONAL, 'regenerate your entities classes.')
            ->addOption('dest-path', null, InputOption::VALUE_OPTIONAL, 'The path to generate your entities classes.')
            ->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Defines a namespace for the generated entity classes, if converted from database.')
            ->setHelp(<<<EOT
The <info>%command.name%</info> command creates the default connections database:

    <info>php %command.full_name%</info>

You can also optionally specify the name of a connection to create the database for:

    <info>php %command.full_name% --connection=default</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $namespace = $input->getOption('namespace');
        if ($namespace) {
            $this->namespace = $namespace . '\Entity';
        }

        // Process destination directory
        $docRoot = '/home/bitrix/ext_www/symfony.loc/src';
        $this->destPathEntity = $docRoot . $this->destPathEntity;
        $this->destPathRepository = $docRoot . $this->destPathRepository;
        $destPath = $input->getOption('dest-path');
        if ($destPath) {
            $this->destPathEntity = $docRoot . $destPath . '/Entity/';
            $this->destPathRepository = $docRoot . $destPath . '/Repository/';
        }

        if (!is_dir($this->destPathEntity)) {
            mkdir($this->destPathEntity, 0775, true);
        }
        if (!is_dir($this->destPathRepository)) {
            mkdir($this->destPathRepository, 0775, true);
        }
        $this->destPathEntity = realpath($this->destPathEntity);
        $this->destPathRepository = realpath($this->destPathRepository);

        if (!file_exists($this->destPathEntity)) {
            throw new InvalidArgumentException(
                sprintf("Mapping destination directory '<info>%s</info>' does not exist.", $this->destPathEntity)
            );
        }
        if (!file_exists($this->destPathRepository)) {
            throw new InvalidArgumentException(
                sprintf("Mapping destination directory '<info>%s</info>' does not exist.", $this->destPathRepository)
            );
        }
        if (!is_writable($this->destPathEntity)) {
            throw new InvalidArgumentException(
                sprintf("Mapping destination directory '<info>%s</info>' does not have write permissions.", $this->destPathEntity)
            );
        }
        if (!is_writable($this->destPathRepository)) {
            throw new InvalidArgumentException(
                sprintf("Mapping destination directory '<info>%s</info>' does not have write permissions.", $this->destPathRepository)
            );
        }
        $connection = $this->_entityManager->getConnection();
        $this->_output = $output;
        $this->import($connection);
        $io->success('You have a new Entity fnd Repository classes! Now make it your own!'.PHP_EOL.'run php bin/console make:entity --regenerate --overwrite');

        return Command::SUCCESS;
    }


    /**
     * @param $connection
     * @return $this
     */
    public function import($connection): void
    {
        $query = 'SHOW TABLES';
        $tables = $connection->fetchAllAssociative($query);
        foreach ($tables as &$table) {
            $currentTable = [];
            $table_name = reset($table);
            $currentTable['name'] = $table_name;
            $query = 'SHOW COLUMNS FROM ' . $table_name;
            $cols = $connection->fetchAllAssociative($query);
            $needPrimaryKey = true;
            foreach ($cols as $col) {
                $column = $this->parseRow($col);
                if (isset($column['strategy']) && $column['strategy'] === 'auto') {
                    $needPrimaryKey = false;
                }
                $currentTable['columns'][$col['Field']] = $column;
            }
            if ($needPrimaryKey) {
                $currentTable = $this->setPrimaryKey($currentTable);
                $this->_output->writeLn('write to ' . $table_name);
                $this->addPrimaryKeyToDatabase($table_name, $connection);
            }
            $this->saveTable($currentTable);
            $this->saveRepository($table_name);
            $this->_output->writeLn('Created ' . $table_name);
        }
    }


    public function saveTable($currentTable)
    {
        $rows = '';
        $table_name = $currentTable['name'];
        foreach ($currentTable['columns'] as $name => $column) {
            if (isset($column['primary'])) {
                $rows .= $this->getTemplatePrimaryRow($name, $column['property']);
            } else {
                $rows .= $this->getTemplateRow($name, $column['property']);
            }
        }
        $content = $this->getTemplateEntity($table_name, $rows);

        file_put_contents($this->destPathEntity . '/' . $table_name . '.php', $content);
    }

    private function saveRepository(string $table_name)
    {
        $content = $this->getTemplateRepo($table_name);
        file_put_contents($this->destPathRepository . '/' . $table_name . 'Repository.php', $content);
    }

    public function getTemplateEntity($className, $context): string
    {
        $template = '<?php /** ' . '@noinspection ALL ' . ' */' . PHP_EOL;
        $template .= 'namespace ' . $this->namespace . '\Entity;' . PHP_EOL;
        $template .= 'use ' . $this->namespace . '\Repository\\' . $className . 'Repository;' . PHP_EOL;
        $template .= 'use Doctrine\ORM\Mapping as ORM;' . PHP_EOL . PHP_EOL;
        $template .= '/** ' . PHP_EOL;
        $template .= '*' . ' @ORM\Entity(repositoryClass=' . $className . 'Repository::class)' . PHP_EOL;
        $template .= '*/' . PHP_EOL;
        $template .= 'class ' . $className . PHP_EOL;
        $template .= '{' . PHP_EOL;
        $template .= $context . PHP_EOL;
        $template .= '}' . PHP_EOL;
        return $template;
    }

    public function getTemplateRepo($className): string
    {
        $template = '<?php' . PHP_EOL;
        $template .= 'namespace ' . $this->namespace . '\Repository;' . PHP_EOL;
        $template .= 'use App\Entity\\' . $className . ';' . PHP_EOL;
        $template .= 'use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;' . PHP_EOL;
        $template .= 'use Doctrine\Persistence\ManagerRegistry;' . PHP_EOL . PHP_EOL;
        $template .= '/**' . PHP_EOL;
        $template .= '* @extends ServiceEntityRepository<' . $className . '>' . PHP_EOL;
        $template .= '* @method ' . $className . '|null find($id, $lockMode = null, $lockVersion = null)' . PHP_EOL;
        $template .= '* @method ' . $className . '|null findOneBy(array $criteria, array $orderBy = null)' . PHP_EOL;
        $template .= '* @method ' . $className . '[]    findAll()' . PHP_EOL;
        $template .= '* @method ' . $className . '[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)' . PHP_EOL;
        $template .= '*/' . PHP_EOL;
        $template .= PHP_EOL;
        $template .= 'class ' . $className . 'Repository extends ServiceEntityRepository' . PHP_EOL;
        $template .= '{' . PHP_EOL;
        $template .= '    public function __construct(ManagerRegistry $registry) ' . PHP_EOL;
        $template .= '    {' . PHP_EOL;
        $template .= '        parent::__construct($registry, ' . $className . '::class);' . PHP_EOL;
        $template .= '     }' . PHP_EOL;
        $template .= PHP_EOL;
        $template .= '    public function add(' . $className . ' $entity, bool $flush = false): void' . PHP_EOL;
        $template .= '    {' . PHP_EOL;
        $template .= '' . PHP_EOL;
        $template .= '        $this->getEntityManager()->persist($entity);' . PHP_EOL;
        $template .= '            if ($flush) {' . PHP_EOL;
        $template .= '                $this->getEntityManager()->flush();' . PHP_EOL;
        $template .= '            }' . PHP_EOL;
        $template .= '     }' . PHP_EOL;
        $template .= PHP_EOL;
        $template .= '    public function remove(' . $className . ' $entity, bool $flush = false): void' . PHP_EOL;
        $template .= '    {' . PHP_EOL;
        $template .= '' . PHP_EOL;
        $template .= '        $this->getEntityManager()->remove($entity);' . PHP_EOL;
        $template .= '            if ($flush) {' . PHP_EOL;
        $template .= '                $this->getEntityManager()->flush();' . PHP_EOL;
        $template .= '            }' . PHP_EOL;
        $template .= '     }' . PHP_EOL;
        $template .= '}' . PHP_EOL;

        return $template;
    }

    public function getTemplateRow($name, $property): string
    {
        $originalName = '';
        if (strtolower($name) !== $name) {
            $originalName = 'name="' . $name.'", ';
        }
        if (floatval($name) > 0) {
            $name = 'b' . $name;
        }
        $template = '/**' . PHP_EOL;
        $template .= '* @ORM\Column('.$originalName . $property . ')' . PHP_EOL;
        $template .= '*/' . PHP_EOL;
        $template .= PHP_EOL;
        $template .= 'private $' . strtolower($name) . ';' . PHP_EOL;
        $template .= PHP_EOL;

        return $template;
    }

    public function getTemplatePrimaryRow($name, $property): string
    {
        $originalName = '';
        if (strtolower($name) !== $name) {
            $originalName = 'name="' . $name.'", ';
        } $template = '/**' . PHP_EOL;
        $template .= '* @ORM\Id' . PHP_EOL;
        $template .= '* @ORM\Column('.$originalName . $property . ')' . PHP_EOL;
        $template .= '* @ORM\GeneratedValue' . PHP_EOL;
        $template .= '*/' . PHP_EOL;
        $template .= PHP_EOL;
        $template .= 'private $' . strtolower($name) . ';' . PHP_EOL;
        $template .= PHP_EOL;

        return $template;
    }

    public function parseRow($row): array
    {
        $result = [
            'property' => [
                'name' => $row['Field']
            ]
        ];
        $parsedType = explode('(', $row['Type']);
        $propertyArray = [];
        $type = strtoupper($parsedType[0]);
        if (isset($parsedType[1])) {
            $parsedLength = explode(')', trim($parsedType[1]));
            $propertyArray['length'] = $parsedLength[0];
            if (isset($parsedLength[1]) && !!$parsedLength[1]) {
                $propertyArray['options'][trim($parsedLength[1])] = 'true';
            }
        }
        if ($row['Default'] !== NULL) {
            $propertyArray['options']['default'] = $row['Default'];
        }
        switch ($type) {
            case 'VARCHAR':
            case 'STRING':
            case 'CHAR':
                $propertyArray['type'] = 'string';
                break;
            case 'TEXT':
            case 'MEDIUMTEXT':
            case 'LONGTEXT':
                $propertyArray['type'] = 'text';
                break;
            case 'INT':
            case 'INTEGER':
            case 'TINYINT':
                $propertyArray['type'] = 'integer';
                break;
            case 'SMALLINT':
                $propertyArray['type'] = 'smallint';
                break;
            case 'BIGINT':
                $propertyArray['type'] = 'bigint';
                break;
            case 'DOUBLE':
            case 'DECIMAL':
                $propertyArray['type'] = 'decimal';
                if (isset($propertyArray['length'])) {
                    $parsed = explode(',', $propertyArray['length']);
                    if (isset($parsed[1]) && $parsed[1] <> '') {
                        $propertyArray['length'] = $parsed[0];
                        $propertyArray['precision'] = $parsed[1];
                    }
                }
                break;
            case 'FLOAT':
                $propertyArray['type'] = 'float';
                break;
            case 'DATETIME':
            case 'TIMESTAMP':
                $propertyArray['type'] = 'datetime';
                break;
            case 'DATE':
                $propertyArray['type'] = 'date';
                break;
            case 'BLOB':
            case 'MEDIUMBLOB':
            case 'LONGBLOB':
                $propertyArray['type'] = 'blob';
                break;
            case 'VARBINARY':
                $propertyArray['type'] = 'binary';
                break;
            case 'ENUM':
                $propertyArray['columnDefinition'] = 'enum(\'Y\',\'N\') DEFAULT \'N\'';
                unset($propertyArray['length']);
                unset($propertyArray['options']);
                break;
            default:
                throw new InvalidArgumentException('undefined field type of ' . $row['Field']);
        }
        if ($row['Null'] === 'YES') {
            $propertyArray['nullable'] = 'true';
        }
        if ($row['Key'] <> '') {
            switch ($row['Key']) {
                case 'PRI':
                    if ($row['Extra'] === 'auto_increment') {
                        $result['strategy'] = 'auto';
                        $result['primary'] = true;
                    }
                    break;
                case 'MUL':
                    break;
                case 'UNI':
                    $propertyArray['unique'] = 'true';
                    break;
                default:
                    throw new InvalidArgumentException('undefined key type of ' . $row['Field']);
            }
        }

        $result['property'] = $this->implodePropertyString($propertyArray);
        return $result;
    }

    public function implodePropertyString($propertyArray): string
    {
        $result = [];
        foreach ($propertyArray as $propName => &$propValue) {
            $opProp = [];
            if ($propName === 'options') {
                foreach ($propValue as $opName => $opValue) {
                    $opProp[] = '"' . $opName . '" : "' . $opValue . '"';
                }
                $propValue = '{' . implode(', ', $opProp) . '}';
                $result[] = $propName . '=' . $propValue;
            } elseif ($propValue === 'true' || $propValue === 'false') {
                $result[] = $propName . '=' . $propValue;
            } else {
                $result[] = $propName . '="' . $propValue . '"';
            }
        }
        return implode(', ', $result);
    }

    private function setPrimaryKey(array $currentTable): array
    {
        $currentTable['columns']['doctrine_id'] = [
            'property' => 'length="11", type="integer"',
            'primary' => true,
            'strategy' => 'auto'
        ];
        return $currentTable;
    }

    private function addPrimaryKeyToDatabase(string $name, $connection): void
    {
        try {
            $sql = "ALTER TABLE `$name` ADD `doctrineId` int(11) NOT NULL AUTO_INCREMENT UNIQUE FIRST;";
            $result = $connection->prepare($sql)->executeQuery()->fetchOne();
            $this->_output->writeLn('create key ' . $result);
        } catch (Exception $exception) {
            $this->_output->writeLn('create key failed');

        }
    }

}

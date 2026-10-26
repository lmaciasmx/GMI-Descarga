<?php

namespace App\Commands;

use App\Common\Services\AuditService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

final class BootstrapAdmin extends BaseCommand
{
    protected $group = 'Grupo Mar';
    protected $name = 'app:bootstrap-admin';
    protected $description = 'Crea el primer propietario del proceso y su alcance operativo.';
    protected $usage = 'app:bootstrap-admin <correo> <usuario>';
    protected $arguments = [
        'correo' => 'Correo electrónico del administrador.',
        'usuario' => 'Nombre corto de usuario.',
    ];

    public function run(array $params): int
    {
        $email = trim((string) ($params[0] ?? CLI::prompt('Correo del administrador')));
        $username = trim((string) ($params[1] ?? CLI::prompt('Nombre de usuario')));
        $validation = service('validation');
        if (! $validation->run(['email' => $email, 'username' => $username], [
            'email' => 'required|valid_email|max_length[254]',
            'username' => 'required|min_length[3]|max_length[30]|alpha_numeric_punct',
        ])) {
            foreach ($validation->getErrors() as $error) {
                CLI::error($error);
            }
            return EXIT_ERROR;
        }

        $company = db_connect()->table('companies')->where('code', 'GMAR')->get()->getRowArray();
        $site = db_connect()->table('sites')->where('code', 'MZO')->get()->getRowArray();
        if ($company === null || $site === null) {
            CLI::error('Ejecuta primero migrations y DatabaseSeeder.');
            return EXIT_ERROR;
        }

        $configuredPassword = trim((string) env('GMAR_BOOTSTRAP_PASSWORD', env('bootstrap.adminPassword', '')));
        $password = $configuredPassword !== ''
            ? $configuredPassword
            : 'Gm!' . bin2hex(random_bytes(10)) . 'aA9';
        $db = db_connect();
        $db->transException(true)->transBegin();

        try {
            $model = model(UserModel::class);
            if ($model->findByCredentials(['email' => $email]) !== null) {
                CLI::error('Ya existe un usuario con ese correo.');
                $db->transRollback();
                return EXIT_ERROR;
            }
            $user = new User(['username' => $username, 'email' => $email, 'password' => $password, 'active' => 1]);
            $model->save($user);
            $user = $model->findById($model->getInsertID());
            if (! $user instanceof User) {
                throw new \RuntimeException('No fue posible recuperar el usuario creado.');
            }
            $user->addGroup('process_owner');
            $db->table('operational_user_scopes')->insert([
                'user_id' => (int) $user->id,
                'company_id' => (int) $company['id'],
                'site_id' => (int) $site['id'],
                'warehouse_id' => null,
                'station_code' => null,
                'active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            (new AuditService($db))->record('user.bootstrap_created', 'user', (int) $user->id, (int) $user->id, (int) $company['id'], (int) $site['id'], null, ['group' => 'process_owner']);
            $db->transCommit();
        } catch (\Throwable $exception) {
            $db->transRollback();
            CLI::error('No se creó el usuario: ' . $exception->getMessage());
            return EXIT_ERROR;
        }

        CLI::write('Administrador creado: ' . $email, 'green');
        if ($configuredPassword === '') {
            CLI::write('Contraseña inicial (se muestra una sola vez): ' . $password, 'yellow');
            CLI::write('Cámbiala inmediatamente con: php spark shield:user password -e ' . $email, 'yellow');
        } else {
            CLI::write('Se utilizó la variable temporal de bootstrap. Elimínala inmediatamente.', 'yellow');
        }

        return EXIT_SUCCESS;
    }
}

<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'admin@grupomar.local';
        $password = 'Admin1234!';

        $users = auth()->getProvider();

        $user = $users->findByCredentials([
            'email' => $email,
        ]);

        if ($user === null) {
            $user = new User([
                'username' => 'admin',
                'email'    => $email,
                'password' => $password,
                'active'   => 1,
            ]);

            $users->save($user);

            $user = $users->findById($users->getInsertID());
        }

        if (! $user->inGroup('process_owner')) {
            $user->addGroup('process_owner');
        }

        $company = $this->db->table('companies')
            ->where('code', 'GMAR')
            ->get()
            ->getRowArray();

        if ($company === null) {
            throw new \RuntimeException(
                'No existe la empresa GMAR. Ejecuta primero CoreCatalogSeeder.'
            );
        }

        $site = $this->db->table('sites')
            ->where('company_id', $company['id'])
            ->where('code', 'MZO')
            ->get()
            ->getRowArray();

        if ($site === null) {
            throw new \RuntimeException(
                'No existe el sitio MZO.'
            );
        }

        $warehouse = $this->db->table('warehouses')
            ->where('company_id', $company['id'])
            ->where('site_id', $site['id'])
            ->where('code', 'FRIO-01')
            ->get()
            ->getRowArray();

        if ($warehouse === null) {
            throw new \RuntimeException(
                'No existe el almacén FRIO-01.'
            );
        }

        $exists = $this->db->table('operational_user_scopes')
            ->where('user_id', $user->id)
            ->where('company_id', $company['id'])
            ->where('site_id', $site['id'])
            ->where('warehouse_id', $warehouse['id'])
            ->where('station_code', 'ADMIN')
            ->countAllResults();

        if ($exists === 0) {
            $this->db->table('operational_user_scopes')->insert([
                'user_id'      => $user->id,
                'company_id'   => $company['id'],
                'site_id'      => $site['id'],
                'warehouse_id' => $warehouse['id'],
                'station_code' => 'ADMIN',
                'active'       => 1,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        echo PHP_EOL;
        echo 'Administrador creado correctamente.' . PHP_EOL;
        echo 'Correo: ' . $email . PHP_EOL;
        echo 'Password: ' . $password . PHP_EOL;
        echo 'Grupo: process_owner' . PHP_EOL;
        echo 'Alcance: GMAR / MZO / FRIO-01' . PHP_EOL;
    }
}
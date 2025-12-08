<?php
// User.php - Modelo para interação com a tabela 'users'

class User {
    private $pdo;
    private $tableName = 'users';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Busca os dados de um usuário pelo ID.
     */
    public function getById($id) {
        if (!is_numeric($id)) return false;
        
        $sql = "SELECT id, email, phone_number, nome, username, photo, password, type, locale, receber_email 
                FROM {$this->tableName} 
                WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Atualiza os dados pessoais do usuário.
     */
    public function updateProfile($id, array $data) {
        if (!is_numeric($id) || empty($data)) return false;

        // Lista de campos permitidos para atualização via formulário de perfil
        $allowedFields = ['nome', 'username', 'phone_number', 'locale', 'receber_email', 'photo']; 
        
        $updateData = [];
        $fields = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "{$key} = :{$key}";
                $updateData[$key] = $value;
            }
        }
        
        if (empty($fields)) return false;

        $sql = "UPDATE {$this->tableName} SET " . implode(', ', $fields) . " WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $updateData['id'] = $id;
        
        return $stmt->execute($updateData);
    }
    
    /**
     * Atualiza a senha do usuário.
     */
    public function updatePassword($id, $hashedPassword) {
        if (!is_numeric($id)) return false;

        $sql = "UPDATE {$this->tableName} SET password = :password WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute([
            'password' => $hashedPassword,
            'id' => $id
        ]);
    }
    
    /**
     * Verifica se o username já está em uso por outro usuário.
     */
     public function isUsernameTaken($username, $currentId) {
        $sql = "SELECT id FROM {$this->tableName} WHERE username = :username AND id != :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['username' => $username, 'id' => $currentId]);
        
        return $stmt->fetchColumn() !== false;
    }

}
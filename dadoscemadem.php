<?php
require_once __DIR__ . '/config/configbd.php';

try {
    // Conecta ao banco de dados usando PDO
    $pdo = Database::getConnection();

    // URL da API
    $url = "https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/MedidaResource.php?est=6622&sen=20&pag=36";

    // Função para obter dados da API
    function obterDadosCemaden($url) {
        // Faz a requisição para a URL
        $response = file_get_contents($url);

        if ($response === FALSE) {
            throw new Exception("Erro ao obter dados da URL: $url");
        }

        // Decodifica os dados JSON
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erro ao decodificar o JSON: " . json_last_error_msg());
        }

        // Processa os dados no formato necessário
        $dadosFormatados = [];
        foreach ($data as $item) {
            // Calcula o nível atual (offset - valor)
            $nivel_atual = floatval($item['offset']) - floatval($item['valor']);

            // Formata o resultado conforme o modelo requerido
            $dadosFormatados[] = [
                'codigo' => $item['codigo'],
                'estacao' => $item['estacao'],
                'cidade' => $item['cidade'],
                'uf' => $item['uf'],
                'datahora' => $item['datahora'], // Manteremos o formato original para conversão posterior
                'valor' => $item['valor'],
                'qualificacao' => $item['qualificacao'],
                'offset' => $item['offset'],
                'cota_atencao' => $item['cota_atencao'],
                'cota_alerta' => $item['cota_alerta'],
                'cota_transbordamento' => $item['cota_transbordamento'],
                'nivel_atual' => $nivel_atual
            ];
        }

        // Retorna os dados formatados
        return $dadosFormatados;
    }

    // Obtém os dados da API
    $dados = obterDadosCemaden($url);

    var_dump($item['valor']);

    // Prepara a inserção no banco de dados
    $stmt = $pdo->prepare(" 
        INSERT INTO leituras_cemaden 
        (data_leitura, hora_leitura, valor, `offset`, cota_atencao, cota_alerta, cota_transbordamento, nivel_atual, estacao_nome, cidade_nome, uf_estado, codigo_estacao, created_at)
        VALUES 
        (:data_leitura, :hora_leitura, :valor, :offset, :cota_atencao, :cota_alerta, :cota_transbordamento, :nivel_atual, :estacao_nome, :cidade_nome, :uf_estado, :codigo_estacao, NOW())
    ");

    foreach ($dados as $item) {
        // Converte data e hora de UTC para São Paulo
        $utcDateTime = new DateTime($item['datahora'], new DateTimeZone('UTC'));
        $utcDateTime->setTimezone(new DateTimeZone('America/Sao_Paulo'));

        $data_leitura = $utcDateTime->format('Y-m-d'); // Data no formato desejado
        $hora_leitura = $utcDateTime->format('H:i');  // Hora no formato desejado

        $valor = $item['valor'];
        $offset = $item['offset'];
        $cota_atencao = $item['cota_atencao'];
        $cota_alerta = $item['cota_alerta'];
        $cota_transbordamento = $item['cota_transbordamento'];
        $nivel_atual = $item['nivel_atual'];

        $estacao_nome = $item['estacao'];
        $cidade_nome = $item['cidade'];
        $uf_estado = $item['uf'];
        $codigo_estacao = $item['codigo'];

        // Verifica se já existe um registro com a mesma data e hora
        $stmt_check = $pdo->prepare("
            SELECT COUNT(*) FROM leituras_cemaden
            WHERE data_leitura = :data_leitura AND hora_leitura = :hora_leitura
        ");
        $stmt_check->bindParam(':data_leitura', $data_leitura);
        $stmt_check->bindParam(':hora_leitura', $hora_leitura);
        $stmt_check->execute();

        if ($stmt_check->fetchColumn() > 0) {
            echo "Registro já existente para data $data_leitura e hora $hora_leitura. Ignorando inserção...\n";
            continue;
        }

        // Insere os dados no banco de dados
        $stmt->bindParam(':data_leitura', $data_leitura);
        $stmt->bindParam(':hora_leitura', $hora_leitura);
        $stmt->bindParam(':valor', $valor);
        $stmt->bindParam(':offset', $offset);
        $stmt->bindParam(':cota_atencao', $cota_atencao);
        $stmt->bindParam(':cota_alerta', $cota_alerta);
        $stmt->bindParam(':cota_transbordamento', $cota_transbordamento);
        $stmt->bindParam(':nivel_atual', $nivel_atual);
        $stmt->bindParam(':estacao_nome', $estacao_nome);
        $stmt->bindParam(':cidade_nome', $cidade_nome);
        $stmt->bindParam(':uf_estado', $uf_estado);
        $stmt->bindParam(':codigo_estacao', $codigo_estacao);

        $stmt->execute();
        echo "Dados inseridos para $data_leitura $hora_leitura.\n";

        // Envia o alerta se o nível atual for maior ou igual à cota de transbordamento
        if ($nivel_atual >= $cota_transbordamento) {
            // Alerta de e-mail
            $to = "andresoaresdiniz201218@gmail.com"; // Substitua pelo seu e-mail
            $subject = "Alerta de Cheia na Estação $estacao_nome";
            $message = "Atenção!\n\nEstação: $estacao_nome\n" .
                       "Cidade: $cidade_nome/$uf_estado\n" .
                       "Data/Hora: $data_leitura $hora_leitura\n" .
                       "Nível Atual: $nivel_atual\n" .
                       "Cota de Alerta: $cota_alerta\n" .
                       "Cota de Transbordamento: $cota_transbordamento\n\n" .
                       "Tome as medidas necessárias!";
            $headers = "From: sac@clouatacado.com";

            if (mail($to, $subject, $message, $headers)) {
                echo "Alerta de e-mail enviado para $to.\n";
            } else {
                echo "Erro ao enviar o alerta de e-mail.\n";
            }

            // Atualiza a tabela coordenadas_interditar para ativar interdição
            $stmt_update = $pdo->prepare("
                UPDATE coordenadas_interditar
                SET ativar_interditar = 1
                WHERE id_estacao = :id_estacao
            ");
            $stmt_update->bindParam(':id_estacao', $codigo_estacao);
            $stmt_update->execute();
            echo "Coordenadas de interdição ativadas para a estação $estacao_nome.\n";
        } else {
            // Se o nível atual for menor que a cota de transbordamento, desativa a interdição
            $stmt_update = $pdo->prepare("
                UPDATE coordenadas_interditar
                SET ativar_interditar = 0
                WHERE id_estacao = :id_estacao
            ");
            $stmt_update->bindParam(':id_estacao', $codigo_estacao);
            $stmt_update->execute();
            echo "Coordenadas de interdição desativadas para a estação $estacao_nome.\n";
        }
    }

} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
?>

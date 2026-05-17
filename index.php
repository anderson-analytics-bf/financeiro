<?php
declare(strict_types=1);

session_start();

$dbHost = '127.0.0.1';
$dbName = 'financeiro';
$dbUser = 'root';
$dbPass = 'Grasiele#720461';

$months = [
    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
];

function redirectWithMessage(string $type, string $message): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function moneyBr(float|string|null $value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalizeMoney(string $value): float
{
    $value = trim($value);
    $value = str_replace(['R$', ' ', '.'], '', $value);
    $value = str_replace(',', '.', $value);
    return (float) $value;
}

function validMonth(string $month, array $months): bool
{
    return in_array($month, $months, true) || in_array(ucfirst(strtolower($month)), $months, true);
}

function normalizeMonth(string $month, array $months): string
{
    $normalize = static function (string $value): string {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return strtolower($converted !== false ? $converted : $value);
    };

    foreach ($months as $validMonth) {
        if ($normalize($validMonth) === $normalize($month)) {
            return $validMonth;
        }
    }

    return $month;
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    http_response_code(500);
    echo 'Erro ao conectar no banco financeiro: ' . h($exception->getMessage());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $type = $_POST['type'] ?? '';

    try {
        if ($type === 'cartao') {
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $cardId = (int) ($_POST['id_cartao'] ?? 0);
                $value = normalizeMoney((string) ($_POST['valor'] ?? '0'));
                $month = normalizeMonth(trim((string) ($_POST['mes'] ?? '')), $months);

                if ($cardId <= 0 || $value <= 0 || !validMonth($month, $months)) {
                    redirectWithMessage('error', 'Preencha cartão, valor e mês corretamente.');
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE despesas SET ID_CARTAO = ?, VALOR = ?, MES = ? WHERE ID = ?');
                    $stmt->execute([$cardId, $value, $month, $id]);
                    redirectWithMessage('success', 'Despesa do cartão atualizada.');
                }

                $stmt = $pdo->prepare('INSERT INTO despesas (ID_CARTAO, VALOR, MES) VALUES (?, ?, ?)');
                $stmt->execute([$cardId, $value, $month]);
                redirectWithMessage('success', 'Despesa do cartão lançada.');
            }

            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM despesas WHERE ID = ?');
                $stmt->execute([$id]);
                redirectWithMessage('success', 'Despesa do cartão deletada.');
            }
        }

        if ($type === 'fixa') {
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $description = trim((string) ($_POST['descricao'] ?? ''));
                $value = normalizeMoney((string) ($_POST['valor'] ?? '0'));
                $month = normalizeMonth(trim((string) ($_POST['mes'] ?? '')), $months);

                if ($description === '' || $value <= 0 || !validMonth($month, $months)) {
                    redirectWithMessage('error', 'Preencha descrição, valor e mês corretamente.');
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE despesas_fixas SET DESCRICAO = ?, VALOR = ?, MES = ? WHERE ID = ?');
                    $stmt->execute([$description, $value, $month, $id]);
                    redirectWithMessage('success', 'Despesa fixa atualizada.');
                }

                $stmt = $pdo->prepare('INSERT INTO despesas_fixas (DESCRICAO, VALOR, MES) VALUES (?, ?, ?)');
                $stmt->execute([$description, $value, $month]);
                redirectWithMessage('success', 'Despesa fixa lançada.');
            }

            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM despesas_fixas WHERE ID = ?');
                $stmt->execute([$id]);
                redirectWithMessage('success', 'Despesa fixa deletada.');
            }
        }

        redirectWithMessage('error', 'Ação inválida.');
    } catch (PDOException $exception) {
        redirectWithMessage('error', 'Erro no banco: ' . $exception->getMessage());
    }
}

$cards = $pdo->query('SELECT ID, CARTAO FROM cartoes ORDER BY CARTAO')->fetchAll();

$cardExpenses = $pdo->query(
    'SELECT d.ID, d.ID_CARTAO, d.VALOR, d.MES, c.CARTAO
     FROM despesas d
     LEFT JOIN cartoes c ON c.ID = d.ID_CARTAO
     ORDER BY d.ID DESC'
)->fetchAll();

$fixedExpenses = $pdo->query(
    'SELECT ID, DESCRICAO, VALOR, MES
     FROM despesas_fixas
     ORDER BY ID DESC'
)->fetchAll();

$totalCards = array_sum(array_map(static fn (array $row): float => (float) $row['VALOR'], $cardExpenses));
$totalFixed = array_sum(array_map(static fn (array $row): float => (float) $row['VALOR'], $fixedExpenses));
$totalGeneral = $totalCards + $totalFixed;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Controle de Gastos</title>
    <style>
        :root {
            --white: #FFFFFF;
            --primary: #7664c8;
            --soft: #f3f6fd;
            --text: #20233a;
            --muted: #747990;
            --line: #e5e9f5;
            --danger: #d94f62;
            --success: #2f9d70;
            --shadow: 0 18px 45px rgba(68, 72, 104, .12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--soft);
            color: var(--text);
            font-family: Inter, Segoe UI, Roboto, Arial, sans-serif;
        }

        .topbar {
            height: 70px;
            background: var(--primary);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 clamp(18px, 4vw, 54px);
            box-shadow: 0 8px 22px rgba(118, 100, 200, .25);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            letter-spacing: 0;
        }

        .brand-mark {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .18);
            display: grid;
            place-items: center;
            font-size: 18px;
        }

        .nav {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .nav span {
            padding: 9px 13px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .14);
            font-size: 13px;
            font-weight: 700;
        }

        main {
            width: min(1180px, calc(100% - 32px));
            margin: 28px auto 44px;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .metric {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
            box-shadow: var(--shadow);
        }

        .metric small {
            color: var(--muted);
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: .05em;
        }

        .metric strong {
            display: block;
            margin-top: 8px;
            font-size: clamp(24px, 3vw, 34px);
        }

        .layout {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }

        .panel {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .panel-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
        }

        h1, h2 {
            margin: 0;
            letter-spacing: 0;
        }

        h1 {
            font-size: clamp(24px, 4vw, 38px);
        }

        h2 {
            font-size: 17px;
        }

        .subtitle {
            margin: 6px 0 0;
            color: rgba(255, 255, 255, .82);
            max-width: 680px;
            line-height: 1.45;
        }

        .hero {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: center;
            margin-bottom: 22px;
            padding: 24px;
            background: linear-gradient(135deg, #7664c8 0%, #8a79d6 100%);
            color: var(--white);
            border-radius: 8px;
            box-shadow: 0 18px 44px rgba(118, 100, 200, .24);
        }

        .hero-badge {
            min-width: 130px;
            padding: 14px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .16);
            text-align: center;
            font-weight: 800;
        }

        form {
            padding: 18px 20px 20px;
        }

        .field {
            margin-bottom: 14px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }

        input, select {
            width: 100%;
            height: 43px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0 12px;
            background: #fbfcff;
            color: var(--text);
            font: inherit;
            outline: none;
        }

        input:focus, select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(118, 100, 200, .12);
        }

        .form-actions, .row-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        button, .button {
            border: 0;
            border-radius: 8px;
            height: 40px;
            padding: 0 14px;
            background: var(--primary);
            color: var(--white);
            font: inherit;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        button.secondary {
            background: var(--soft);
            color: var(--primary);
        }

        button.danger {
            background: #fff0f2;
            color: var(--danger);
        }

        .tabs {
            display: flex;
            gap: 8px;
            padding: 12px;
            background: var(--soft);
            border-bottom: 1px solid var(--line);
        }

        .tab-button {
            background: transparent;
            color: var(--muted);
            height: 38px;
        }

        .tab-button.active {
            background: var(--primary);
            color: var(--white);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: middle;
        }

        th {
            color: var(--muted);
            background: #fbfcff;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        tbody tr:hover {
            background: #fafbff;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            height: 28px;
            border-radius: 8px;
            padding: 0 10px;
            background: var(--soft);
            color: var(--primary);
            font-weight: 800;
            font-size: 12px;
        }

        .value {
            font-weight: 900;
        }

        .empty {
            padding: 32px;
            color: var(--muted);
            text-align: center;
        }

        .flash {
            margin-bottom: 16px;
            padding: 13px 16px;
            border-radius: 8px;
            font-weight: 700;
            background: #eef9f4;
            color: var(--success);
            border: 1px solid #ccefe0;
        }

        .flash.error {
            background: #fff0f2;
            color: var(--danger);
            border-color: #ffd0d7;
        }

        @media (max-width: 920px) {
            .summary, .layout {
                grid-template-columns: 1fr;
            }

            .hero {
                align-items: flex-start;
                flex-direction: column;
            }

            .nav {
                display: none;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">
            <div class="brand-mark">C$</div>
            <span>Controle de Gastos</span>
        </div>
        <nav class="nav" aria-label="Seções">
            <span>Despesas</span>
            <span>Cartões</span>
            <span>Fixas</span>
        </nav>
    </header>

    <main>
        <section class="hero">
            <div>
                <h1>Cadastro de despesas</h1>
                <p class="subtitle">Lance, edite e delete despesas do cartão e despesas fixas usando o banco financeiro existente.</p>
            </div>
            <div class="hero-badge">
                <?= h(date('m/Y')) ?>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="flash <?= h($flash['type']) ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <section class="summary" aria-label="Resumo">
            <div class="metric">
                <small>Total cartão</small>
                <strong><?= h(moneyBr($totalCards)) ?></strong>
            </div>
            <div class="metric">
                <small>Total fixas</small>
                <strong><?= h(moneyBr($totalFixed)) ?></strong>
            </div>
            <div class="metric">
                <small>Total geral</small>
                <strong><?= h(moneyBr($totalGeneral)) ?></strong>
            </div>
        </section>

        <section class="layout">
            <aside class="panel">
                <div class="tabs" role="tablist">
                    <button class="tab-button active" type="button" data-tab="form-card">Cartão</button>
                    <button class="tab-button" type="button" data-tab="form-fixed">Fixa</button>
                </div>

                <div id="form-card" class="tab-content active">
                    <div class="panel-header">
                        <h2 id="card-form-title">Lançar despesa do cartão</h2>
                    </div>
                    <form method="post" id="card-form">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="type" value="cartao">
                        <input type="hidden" name="id" id="card-id">

                        <div class="field">
                            <label for="card-id-card">Cartão</label>
                            <select name="id_cartao" id="card-id-card" required>
                                <option value="">Selecione</option>
                                <?php foreach ($cards as $card): ?>
                                    <option value="<?= h($card['ID']) ?>"><?= h($card['CARTAO']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="card-value">Valor</label>
                            <input type="text" name="valor" id="card-value" inputmode="decimal" placeholder="0,00" required>
                        </div>

                        <div class="field">
                            <label for="card-month">Mês</label>
                            <select name="mes" id="card-month" required>
                                <option value="">Selecione</option>
                                <?php foreach ($months as $month): ?>
                                    <option value="<?= h($month) ?>"><?= h($month) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-actions">
                            <button type="submit">Salvar</button>
                            <button class="secondary" type="button" data-reset="card">Limpar</button>
                        </div>
                    </form>
                </div>

                <div id="form-fixed" class="tab-content">
                    <div class="panel-header">
                        <h2 id="fixed-form-title">Lançar despesa fixa</h2>
                    </div>
                    <form method="post" id="fixed-form">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="type" value="fixa">
                        <input type="hidden" name="id" id="fixed-id">

                        <div class="field">
                            <label for="fixed-description">Descrição</label>
                            <input type="text" name="descricao" id="fixed-description" placeholder="Ex.: Internet" required>
                        </div>

                        <div class="field">
                            <label for="fixed-value">Valor</label>
                            <input type="text" name="valor" id="fixed-value" inputmode="decimal" placeholder="0,00" required>
                        </div>

                        <div class="field">
                            <label for="fixed-month">Mês</label>
                            <select name="mes" id="fixed-month" required>
                                <option value="">Selecione</option>
                                <?php foreach ($months as $month): ?>
                                    <option value="<?= h($month) ?>"><?= h($month) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-actions">
                            <button type="submit">Salvar</button>
                            <button class="secondary" type="button" data-reset="fixed">Limpar</button>
                        </div>
                    </form>
                </div>
            </aside>

            <section class="panel">
                <div class="tabs" role="tablist">
                    <button class="tab-button active" type="button" data-tab="list-card">Despesas do cartão</button>
                    <button class="tab-button" type="button" data-tab="list-fixed">Despesas fixas</button>
                </div>

                <div id="list-card" class="tab-content active">
                    <div class="panel-header">
                        <h2>Despesas do cartão</h2>
                        <span class="pill"><?= count($cardExpenses) ?> registros</span>
                    </div>
                    <?php if (!$cardExpenses): ?>
                        <div class="empty">Nenhuma despesa do cartão cadastrada.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Cartão</th>
                                        <th>Mês</th>
                                        <th>Valor</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cardExpenses as $expense): ?>
                                        <tr>
                                            <td>#<?= h($expense['ID']) ?></td>
                                            <td><span class="pill"><?= h($expense['CARTAO'] ?? 'Sem cartão') ?></span></td>
                                            <td><?= h($expense['MES']) ?></td>
                                            <td class="value"><?= h(moneyBr($expense['VALOR'])) ?></td>
                                            <td>
                                                <div class="row-actions">
                                                    <button
                                                        class="secondary"
                                                        type="button"
                                                        data-edit-card
                                                        data-id="<?= h($expense['ID']) ?>"
                                                        data-card="<?= h($expense['ID_CARTAO']) ?>"
                                                        data-value="<?= h(number_format((float) $expense['VALOR'], 2, ',', '.')) ?>"
                                                        data-month="<?= h(normalizeMonth((string) $expense['MES'], $months)) ?>"
                                                    >Editar</button>
                                                    <form method="post" onsubmit="return confirm('Deseja deletar esta despesa?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="type" value="cartao">
                                                        <input type="hidden" name="id" value="<?= h($expense['ID']) ?>">
                                                        <button class="danger" type="submit">Deletar</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="list-fixed" class="tab-content">
                    <div class="panel-header">
                        <h2>Despesas fixas</h2>
                        <span class="pill"><?= count($fixedExpenses) ?> registros</span>
                    </div>
                    <?php if (!$fixedExpenses): ?>
                        <div class="empty">Nenhuma despesa fixa cadastrada.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Descrição</th>
                                        <th>Mês</th>
                                        <th>Valor</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fixedExpenses as $expense): ?>
                                        <tr>
                                            <td>#<?= h($expense['ID']) ?></td>
                                            <td><?= h($expense['DESCRICAO']) ?></td>
                                            <td><?= h($expense['MES']) ?></td>
                                            <td class="value"><?= h(moneyBr($expense['VALOR'])) ?></td>
                                            <td>
                                                <div class="row-actions">
                                                    <button
                                                        class="secondary"
                                                        type="button"
                                                        data-edit-fixed
                                                        data-id="<?= h($expense['ID']) ?>"
                                                        data-description="<?= h($expense['DESCRICAO']) ?>"
                                                        data-value="<?= h(number_format((float) $expense['VALOR'], 2, ',', '.')) ?>"
                                                        data-month="<?= h(normalizeMonth((string) $expense['MES'], $months)) ?>"
                                                    >Editar</button>
                                                    <form method="post" onsubmit="return confirm('Deseja deletar esta despesa fixa?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="type" value="fixa">
                                                        <input type="hidden" name="id" value="<?= h($expense['ID']) ?>">
                                                        <button class="danger" type="submit">Deletar</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </section>
    </main>

    <script>
        const tabs = document.querySelectorAll('[data-tab]');

        function activateTab(targetId) {
            const group = document.getElementById(targetId)?.parentElement;
            if (!group) return;

            group.querySelectorAll('.tab-content').forEach((content) => {
                content.classList.toggle('active', content.id === targetId);
            });

            group.querySelectorAll('[data-tab]').forEach((button) => {
                button.classList.toggle('active', button.dataset.tab === targetId);
            });
        }

        tabs.forEach((button) => {
            button.addEventListener('click', () => activateTab(button.dataset.tab));
        });

        function resetCardForm() {
            document.getElementById('card-form').reset();
            document.getElementById('card-id').value = '';
            document.getElementById('card-form-title').textContent = 'Lançar despesa do cartão';
        }

        function resetFixedForm() {
            document.getElementById('fixed-form').reset();
            document.getElementById('fixed-id').value = '';
            document.getElementById('fixed-form-title').textContent = 'Lançar despesa fixa';
        }

        document.querySelectorAll('[data-reset]').forEach((button) => {
            button.addEventListener('click', () => {
                button.dataset.reset === 'card' ? resetCardForm() : resetFixedForm();
            });
        });

        document.querySelectorAll('[data-edit-card]').forEach((button) => {
            button.addEventListener('click', () => {
                activateTab('form-card');
                document.getElementById('card-id').value = button.dataset.id;
                document.getElementById('card-id-card').value = button.dataset.card;
                document.getElementById('card-value').value = button.dataset.value;
                document.getElementById('card-month').value = button.dataset.month;
                document.getElementById('card-form-title').textContent = 'Editar despesa do cartão';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        document.querySelectorAll('[data-edit-fixed]').forEach((button) => {
            button.addEventListener('click', () => {
                activateTab('form-fixed');
                document.getElementById('fixed-id').value = button.dataset.id;
                document.getElementById('fixed-description').value = button.dataset.description;
                document.getElementById('fixed-value').value = button.dataset.value;
                document.getElementById('fixed-month').value = button.dataset.month;
                document.getElementById('fixed-form-title').textContent = 'Editar despesa fixa';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    </script>
</body>
</html>

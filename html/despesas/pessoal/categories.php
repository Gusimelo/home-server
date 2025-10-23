<?php
// Forçar o PHP a não fazer cache
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$selected_year_from_index = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Categorias</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .modal { display: none; }
        .modal.is-open { display: flex; }
    </style>
</head>
<body class="p-4 sm:p-6 md:p-8">
    <div class="max-w-4xl mx-auto">
        <header class="mb-8">
            <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
                <div>
                    <h1 class="text-3xl sm:text-4xl font-bold text-gray-800">Gestão de Categorias</h1>
                    <p class="text-gray-600 mt-2">Adicione, edite ou remova centros de custo e subcategorias.</p>
                </div>
                <div class="flex items-center gap-4">
                    <button id="move-by-description-btn" class="text-sm font-medium text-blue-600 hover:text-blue-800 whitespace-nowrap">Mover por Descrição</button>
                    <button id="move-transactions-btn" class="text-sm font-medium text-green-600 hover:text-green-800 whitespace-nowrap">Mover por Categoria</button>
                    <a href="index.php?year=<?php echo $selected_year_from_index; ?>" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">&larr; Voltar à Aplicação</a>
                </div>
            </div>
        </header>

        <main class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Coluna Centros de Custo -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 sm:p-6 border-b border-gray-200 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-gray-800">Centros de Custo</h2>
                    <button id="add-cost-center-btn" class="bg-indigo-600 text-white text-sm font-semibold py-1 px-3 rounded-lg shadow hover:bg-indigo-700">+</button>
                </div>
                <ul id="cost-centers-list" class="p-4 sm:p-6 space-y-2">
                    <!-- Lista de centros de custo -->
                </ul>
            </div>
            <!-- Coluna Subcategorias -->
            <div class="bg-white rounded-lg shadow">
                 <div class="p-4 sm:p-6 border-b border-gray-200">
                    <h2 class="text-xl font-bold text-gray-800">Subcategorias</h2>
                    <p id="subcategories-helper" class="text-sm text-gray-500 mt-1">Selecione um centro de custo para ver as suas subcategorias.</p>
                </div>
                <div id="subcategories-container" class="p-4 sm:p-6 hidden">
                    <div class="mb-4">
                        <button id="add-subcategory-btn" class="w-full bg-green-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-green-700">Adicionar Subcategoria Raiz</button>
                    </div>
                    <ul id="subcategories-list" class="space-y-2">
                        <!-- Lista de subcategorias em árvore -->
                    </ul>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Centro de Custo -->
    <div id="cost-center-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm"><form id="cost-center-form" class="p-6"><input type="hidden" id="cost-center-id" name="id"><h2 id="cost-center-modal-title" class="text-xl font-bold text-gray-800 mb-4">Novo Centro de Custo</h2><div><label for="cost-center-name" class="block text-sm font-medium text-gray-700">Nome</label><input type="text" id="cost-center-name" name="name" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></div><div class="mt-6 flex justify-end gap-4"><button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button><button type="submit" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-indigo-700">Guardar</button></div></form></div>
    </div>

    <!-- Modal Subcategoria -->
    <div id="subcategory-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm"><form id="subcategory-form" class="p-6"><input type="hidden" id="subcategory-id" name="id"><input type="hidden" id="parent-subcategory-id" name="parent_id"><h2 id="subcategory-modal-title" class="text-xl font-bold text-gray-800 mb-4">Nova Subcategoria</h2><div><label for="subcategory-name" class="block text-sm font-medium text-gray-700">Nome</label><input type="text" id="subcategory-name" name="name" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></div><div class="mt-6 flex justify-end gap-4"><button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button><button type="submit" class="bg-green-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-green-700">Guardar</button></div></form></div>
    </div>

    <!-- Modal Mover Subcategoria -->
    <div id="move-subcategory-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm">
            <form id="move-subcategory-form" class="p-6">
                <input type="hidden" id="move-subcategory-id" name="subcategory_id">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Mover Subcategoria</h2>
                <p id="move-subcategory-text" class="text-sm text-gray-600 mb-4"></p>
                <div>
                    <label for="move-to-cost-center" class="block text-sm font-medium text-gray-700">Mover para Centro de Custo</label>
                    <select id="move-to-cost-center" name="new_cost_center_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select>
                </div>
                <div class="mt-6 flex justify-end gap-4">
                    <button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="bg-purple-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-purple-700">Mover</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Mover por Categoria -->
    <div id="move-transactions-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg"><form id="move-transactions-form" class="p-6"><h2 class="text-xl font-bold text-gray-800 mb-2">Mover por Categoria</h2><p class="text-sm text-gray-600 mb-4">Mova todos os movimentos de uma categoria de origem para uma categoria de destino. Esta ação é irreversível.</p><div class="grid grid-cols-1 md:grid-cols-2 gap-6"><div class="p-4 border rounded-lg"><h3 class="font-semibold text-gray-700 mb-2">De:</h3><div class="space-y-3"><div><label for="from-cost-center" class="block text-sm font-medium text-gray-700">Centro de Custo</label><select id="from-cost-center" name="from_cost_center" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div><div><label for="from-subcategory" class="block text-sm font-medium text-gray-700">Subcategoria</label><select id="from-subcategory" name="from_subcategory" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div><div id="from-sub-subcategory-wrapper" class="hidden"><label for="from-sub-subcategory" class="block text-sm font-medium text-gray-700">Sub-Subcategoria</label><select id="from-sub-subcategory" name="from_sub_subcategory" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div></div></div><div class="p-4 border rounded-lg"><h3 class="font-semibold text-gray-700 mb-2">Para:</h3><div class="space-y-3"><div><label for="to-cost-center" class="block text-sm font-medium text-gray-700">Centro de Custo</label><select id="to-cost-center" name="to_cost_center" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div><div><label for="to-subcategory" class="block text-sm font-medium text-gray-700">Subcategoria</label><select id="to-subcategory" name="to_subcategory" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div><div id="to-sub-subcategory-wrapper" class="hidden"><label for="to-sub-subcategory" class="block text-sm font-medium text-gray-700">Sub-Subcategoria</label><select id="to-sub-subcategory" name="to_sub_subcategory" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div></div></div></div><div class="mt-6 flex justify-end gap-4"><button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button><button type="submit" class="bg-green-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-green-700">Mover Movimentos</button></div></form></div>
    </div>

    <!-- Modal Mover por Descrição -->
    <div id="move-by-description-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg"><form id="move-by-description-form" class="p-6"><h2 class="text-xl font-bold text-gray-800 mb-2">Mover por Descrição</h2><p class="text-sm text-gray-600 mb-4">Mova todos os movimentos cuja descrição contenha o texto especificado. Esta ação é irreversível.</p><div class="space-y-4"><div><label for="move-description-text" class="block text-sm font-medium text-gray-700">Texto na Descrição (sensível a maiúsculas/minúsculas)</label><input type="text" id="move-description-text" name="description_text" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: Netflix"></div><div class="p-4 border rounded-lg"><h3 class="font-semibold text-gray-700 mb-2">Mover Para:</h3><div class="space-y-3"><div><label for="desc-to-cost-center" class="block text-sm font-medium text-gray-700">Centro de Custo</label><select id="desc-to-cost-center" name="to_cost_center" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div><div><label for="desc-to-subcategory" class="block text-sm font-medium text-gray-700">Subcategoria</label><select id="desc-to-subcategory" name="to_subcategory" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div><div id="desc-to-sub-subcategory-wrapper" class="hidden"><label for="desc-to-sub-subcategory" class="block text-sm font-medium text-gray-700">Sub-Subcategoria</label><select id="desc-to-sub-subcategory" name="to_sub_subcategory" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div></div></div></div><div class="mt-6 flex justify-end gap-4"><button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button><button type="submit" class="bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-blue-700">Mover Movimentos</button></div></form></div>
    </div>

    <script src="assets/js/categories.js" defer></script>
</body>
</html>
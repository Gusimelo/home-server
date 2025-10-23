// Ficheiro JS para categories.php
document.addEventListener('DOMContentLoaded', function () {
    const API_ENDPOINT = 'api.php';
    let costCenters = {};
    let flatSubcategories = {};
    let selectedCostCenterId = null;

    // --- Elementos do DOM ---
    const costCentersList = document.getElementById('cost-centers-list');
    const subcategoriesContainer = document.getElementById('subcategories-container');
    const subcategoriesList = document.getElementById('subcategories-list');
    const subcategoriesHelper = document.getElementById('subcategories-helper');
    
    const costCenterModal = document.getElementById('cost-center-modal');
    const costCenterForm = document.getElementById('cost-center-form');
    const subcategoryModal = document.getElementById('subcategory-modal');
    const subcategoryForm = document.getElementById('subcategory-form');

    const moveTransactionsModal = document.getElementById('move-transactions-modal');
    const moveTransactionsForm = document.getElementById('move-transactions-form');
    const moveSubcategoryModal = document.getElementById('move-subcategory-modal');
    const moveSubcategoryForm = document.getElementById('move-subcategory-form');
    const fromCostCenterSelect = document.getElementById('from-cost-center');
    const fromSubcategorySelect = document.getElementById('from-subcategory');
    const fromSubSubcategoryWrapper = document.getElementById('from-sub-subcategory-wrapper');
    const fromSubSubcategorySelect = document.getElementById('from-sub-subcategory');
    const toCostCenterSelect = document.getElementById('to-cost-center');
    const toSubcategorySelect = document.getElementById('to-subcategory');
    const toSubSubcategoryWrapper = document.getElementById('to-sub-subcategory-wrapper');
    const toSubSubcategorySelect = document.getElementById('to-sub-subcategory');

    const moveByDescriptionModal = document.getElementById('move-by-description-modal');
    const moveByDescriptionForm = document.getElementById('move-by-description-form');
    const descToCostCenterSelect = document.getElementById('desc-to-cost-center');
    const descToSubcategorySelect = document.getElementById('desc-to-subcategory');
    const descToSubSubcategoryWrapper = document.getElementById('desc-to-sub-subcategory-wrapper');
    const descToSubSubcategorySelect = document.getElementById('desc-to-sub-subcategory');

    // --- Funções ---
    const fetchData = async () => {
        try {
            const response = await fetch(`${API_ENDPOINT}?action=get`);
            const result = await response.json();
            if (result.success) {
                costCenters = result.data.cost_centers;

                flatSubcategories = {};
                Object.values(costCenters).forEach(cc => {
                    const flatten = (sc) => {
                        flatSubcategories[sc.id] = sc;
                        if(sc.children) Object.values(sc.children).forEach(flatten);
                    };
                    Object.values(cc.subcategories).forEach(flatten);
                });

                renderCostCenters();
                populateMoveDropdowns();
                if (selectedCostCenterId) {
                    renderSubcategories();
                }
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            alert('Erro ao carregar dados: ' + error.message);
        }
    };

    const handleApiResponse = async (response, modalToClose = null) => {
        const result = await response.json();
        if (result.success) {
            if (modalToClose) modalToClose.classList.remove('is-open');
            if (result.data && result.data.affected_rows) {
                alert(`${result.data.affected_rows} movimentos foram atualizados com sucesso!`);
            }
            fetchData();
        } else {
            alert('Erro: ' + (result.error || 'Ocorreu um problema no servidor.'));
        }
    };

    const renderCostCenters = () => {
        costCentersList.innerHTML = Object.values(costCenters).map(cc => `
            <li id="cc-${cc.id}" class="p-2 rounded-lg flex justify-between items-center cursor-pointer hover:bg-gray-100 ${selectedCostCenterId == cc.id ? 'bg-indigo-100' : ''}">
                <span class="font-medium text-gray-800">${cc.name}</span>
                <div class="flex items-center space-x-2">
                    <button class="text-indigo-600 hover:text-indigo-900" onclick="editCostCenter(event, ${cc.id}, '${cc.name}')">Editar</button>
                    <button class="text-red-600 hover:text-red-900" onclick="deleteCostCenter(event, ${cc.id})">Apagar</button>
                </div>
            </li>
        `).join('');
        document.querySelectorAll('#cost-centers-list li').forEach((li) => {
            const ccId = li.id.split('-')[1];
            li.addEventListener('click', () => selectCostCenter(ccId));
        });
    };

    const renderSubcategoryTree = (subcategories, isRoot = false) => {
        if (Object.keys(subcategories).length === 0) return '';
        return '<ul class="ml-4 pl-4 border-l border-gray-200 space-y-2">' + Object.values(subcategories).map(sc => `
            <li class="p-2 rounded-lg hover:bg-gray-50">
                <div class="flex justify-between items-center">
                    <span>${sc.name}</span>
                    <div class="flex items-center space-x-1 text-sm">
                        ${isRoot ? `<button title="Mover" class="p-1 text-purple-600 hover:text-purple-900" onclick="openMoveSubcategoryModal(event, ${sc.id}, '${sc.name}')"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg></button>` : ''}
                        <button title="Adicionar Sub-nível" class="p-1 text-green-600 hover:text-green-900" onclick="addSubcategory(event, ${sc.id})"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" /></svg></button>
                        <button title="Editar" class="p-1 text-indigo-600 hover:text-indigo-900" onclick="editSubcategory(event, ${sc.id}, '${sc.name}')"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg></button>
                        <button title="Apagar" class="p-1 text-red-600 hover:text-red-900" onclick="deleteSubcategory(event, ${sc.id})"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg></button>
                    </div>
                </div>
                ${sc.children ? renderSubcategoryTree(sc.children, false) : ''}
            </li>
        `).join('') + '</ul>';
    };
    
    const renderSubcategories = () => {
        if (!selectedCostCenterId || !costCenters[selectedCostCenterId]) {
            subcategoriesContainer.classList.add('hidden');
            subcategoriesHelper.classList.remove('hidden');
            return;
        }
        const costCenter = costCenters[selectedCostCenterId];
        subcategoriesHelper.classList.add('hidden');
        subcategoriesContainer.classList.remove('hidden');
        subcategoriesList.innerHTML = renderSubcategoryTree(costCenter.subcategories, true);
    };
    
    window.selectCostCenter = (id) => {
        selectedCostCenterId = id;
        document.querySelectorAll('#cost-centers-list li').forEach(li => li.classList.remove('bg-indigo-100'));
        document.getElementById(`cc-${id}`).classList.add('bg-indigo-100');
        renderSubcategories();
    };

    // --- Funções para Mover Movimentos ---
    const populateMoveDropdowns = () => {
        const costCenterOptions = '<option value="">-- Centro de Custo --</option>' + Object.values(costCenters).map(c => `<option value="${c.name}">${c.name}</option>`).join('');
        fromCostCenterSelect.innerHTML = costCenterOptions;
        toCostCenterSelect.innerHTML = costCenterOptions;
        descToCostCenterSelect.innerHTML = costCenterOptions;
        updateMoveSubcategories('from');
        updateMoveSubcategories('to');
        updateMoveSubcategories('desc-to');
    };

    const updateMoveSubcategories = (type) => {
        const ccSelect = (type === 'from') ? fromCostCenterSelect : (type === 'to' ? toCostCenterSelect : descToCostCenterSelect);
        const subSelect = (type === 'from') ? fromSubcategorySelect : (type === 'to' ? toSubcategorySelect : descToSubcategorySelect);
        const selectedCostCenterName = ccSelect.value;
        
        subSelect.innerHTML = '<option value="">-- Subcategoria --</option>';
        if (selectedCostCenterName) {
            const costCenter = Object.values(costCenters).find(cc => cc.name === selectedCostCenterName);
            if (costCenter && costCenter.subcategories) {
                Object.values(costCenter.subcategories).forEach(sc => {
                    subSelect.innerHTML += `<option value="${sc.id}">${sc.name}</option>`;
                });
            }
        }
        updateMoveSubSubcategories(type);
    };
    
    const updateMoveSubSubcategories = (type) => {
        const subSelect = (type === 'from') ? fromSubcategorySelect : (type === 'to' ? toSubcategorySelect : descToSubcategorySelect);
        const subSubWrapper = (type === 'from') ? fromSubSubcategoryWrapper : (type === 'to' ? toSubSubcategoryWrapper : descToSubSubcategoryWrapper);
        const subSubSelect = (type === 'from') ? fromSubSubcategorySelect : (type === 'to' ? toSubcategorySelect : descToSubcategorySelect);
        const selectedSubCatId = subSelect.value;
        
        subSubSelect.innerHTML = '<option value="">-- Opcional --</option>';
        const parent = flatSubcategories[selectedSubCatId];

        if (parent && parent.children) {
            subSubSelect.innerHTML += Object.values(parent.children).map(ssc => `<option value="${ssc.name}">${ssc.name}</option>`).join('');
            subSubWrapper.classList.remove('hidden');
        } else {
            subSubWrapper.classList.add('hidden');
        }
    };
    
    // --- Event Handlers para Modals ---
    document.getElementById('add-cost-center-btn').addEventListener('click', () => {
        costCenterForm.reset();
        document.getElementById('cost-center-modal-title').textContent = 'Novo Centro de Custo';
        document.getElementById('cost-center-id').value = '';
        costCenterModal.classList.add('is-open');
    });
    
    document.getElementById('add-subcategory-btn').addEventListener('click', (e) => addSubcategory(e, null));
    document.getElementById('move-transactions-btn').addEventListener('click', () => moveTransactionsModal.classList.add('is-open'));
    document.getElementById('move-by-description-btn').addEventListener('click', () => moveByDescriptionModal.classList.add('is-open'));

    window.openMoveSubcategoryModal = (event, subcategoryId, subcategoryName) => {
        event.stopPropagation();
        moveSubcategoryForm.reset();
        
        document.getElementById('move-subcategory-id').value = subcategoryId;
        document.getElementById('move-subcategory-text').textContent = `Mover "${subcategoryName}" e todas as suas subcategorias para um novo centro de custo.`;

        const currentCostCenterId = selectedCostCenterId;
        const moveToSelect = document.getElementById('move-to-cost-center');
        moveToSelect.innerHTML = Object.values(costCenters)
            .filter(cc => cc.id != currentCostCenterId)
            .map(cc => `<option value="${cc.id}">${cc.name}</option>`)
            .join('');
        
        moveSubcategoryModal.classList.add('is-open');
    };

    window.addSubcategory = (event, parentId) => {
        event.stopPropagation();
        subcategoryForm.reset();
        document.getElementById('subcategory-modal-title').textContent = parentId ? 'Nova Sub-Subcategoria' : 'Nova Subcategoria';
        document.getElementById('subcategory-id').value = '';
        document.getElementById('parent-subcategory-id').value = parentId || '';
        subcategoryModal.classList.add('is-open');
    };

    window.editCostCenter = (event, id, name) => {
        event.stopPropagation();
        costCenterForm.reset();
        document.getElementById('cost-center-modal-title').textContent = 'Editar Centro de Custo';
        document.getElementById('cost-center-id').value = id;
        document.getElementById('cost-center-name').value = name;
        costCenterModal.classList.add('is-open');
    };

    window.editSubcategory = (event, id, name) => {
        event.stopPropagation();
        subcategoryForm.reset();
        document.getElementById('subcategory-modal-title').textContent = 'Editar Subcategoria';
        document.getElementById('subcategory-id').value = id;
        document.getElementById('subcategory-name').value = name;
        document.getElementById('parent-subcategory-id').value = ''; // Editing doesn't change parent
        subcategoryModal.classList.add('is-open');
    };

    window.deleteCostCenter = async (event, id) => {
        event.stopPropagation();
        if (confirm('Tem a certeza? Isto irá apagar o centro de custo e todas as subcategorias associadas.')) {
            const response = await fetch(API_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_cost_center', id })
            });
            await handleApiResponse(response);
            selectedCostCenterId = null;
            renderSubcategories();
        }
    };

    window.deleteSubcategory = async (event, id) => {
        event.stopPropagation();
        if (confirm('Tem a certeza? Os movimentos existentes ficarão sem esta subcategoria.')) {
             const response = await fetch(API_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_subcategory', id })
            });
            await handleApiResponse(response);
        }
    };

    costCenterForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('cost-center-id').value;
        const name = document.getElementById('cost-center-name').value;
        const action = id ? 'update_cost_center' : 'add_cost_center';
        
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, id, name })
        });
        await handleApiResponse(response, costCenterModal);
    });
    
    subcategoryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('subcategory-id').value;
        const parent_id = document.getElementById('parent-subcategory-id').value;
        const name = document.getElementById('subcategory-name').value;
        const action = id ? 'update_subcategory' : 'add_subcategory';

        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, id, name, parent_id, cost_center_id: selectedCostCenterId })
        });
        await handleApiResponse(response, subcategoryModal);
    });

    moveSubcategoryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(moveSubcategoryForm);
        formData.append('action', 'move_subcategory');

        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            body: new URLSearchParams(formData)
        });
        await handleApiResponse(response, moveSubcategoryModal);
        // After moving, the current selection is invalid, so reset it
        selectedCostCenterId = null;
        renderSubcategories();
    });

    fromCostCenterSelect.addEventListener('change', () => updateMoveSubcategories('from'));
    fromSubcategorySelect.addEventListener('change', () => updateMoveSubSubcategories('from'));
    toCostCenterSelect.addEventListener('change', () => updateMoveSubcategories('to'));
    toSubcategorySelect.addEventListener('change', () => updateMoveSubSubcategories('to'));
    descToCostCenterSelect.addEventListener('change', () => updateMoveSubcategories('desc-to'));
    descToSubcategorySelect.addEventListener('change', () => updateMoveSubSubcategories('desc-to'));

    moveTransactionsForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(moveTransactionsForm);
        const fromSubCatId = formData.get('from_subcategory');
        const toSubCatId = formData.get('to_subcategory');

        if (fromSubCatId && flatSubcategories[fromSubCatId]) {
            formData.set('from_subcategory', flatSubcategories[fromSubCatId].name);
        }
        if (toSubCatId && flatSubcategories[toSubCatId]) {
            formData.set('to_subcategory', flatSubcategories[toSubCatId].name);
        }
        
        formData.append('action', 'move_transactions');

        if(confirm('Tem a certeza que quer mover todos os movimentos? Esta ação é irreversível.')) {
            const response = await fetch(API_ENDPOINT, {
                method: 'POST',
                body: new URLSearchParams(formData)
            });
            await handleApiResponse(response, moveTransactionsModal);
        }
    });

    moveByDescriptionForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(moveByDescriptionForm);
        const toSubCatId = formData.get('to_subcategory');

        if (toSubCatId && flatSubcategories[toSubCatId]) {
            formData.set('to_subcategory', flatSubcategories[toSubCatId].name);
        }

        formData.append('action', 'move_transactions_by_description');

        if(confirm('Tem a certeza que quer mover os movimentos com esta descrição? Esta ação é irreversível.')) {
            const response = await fetch(API_ENDPOINT, {
                method: 'POST',
                body: new URLSearchParams(formData)
            });
            await handleApiResponse(response, moveByDescriptionModal);
        }
    });

    document.querySelectorAll('.cancel-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.target.closest('.modal').classList.remove('is-open');
        });
    });

    fetchData();
});
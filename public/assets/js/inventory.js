(() => {
    'use strict';

    const chamber = document.getElementById('inventoryChamber');
    const sector = document.getElementById('inventorySector');
    const species = document.getElementById('inventorySpecies');
    const size = document.getElementById('inventorySize');
    const group = document.getElementById('inventoryGroup');
    const groupForm = document.getElementById('inventoryGroupForm');

    const filterSelect = (select, attr, selectedValue) => {
        if (!select) return;

        const selectedId = Number(selectedValue || 0);

        Array.from(select.options).forEach((option, index) => {
            if (index === 0) return;

            const ownerId = Number(option.dataset[attr] || 0);
            option.hidden = selectedId > 0 && ownerId > 0 && ownerId !== selectedId;

            if (option.hidden && option.selected) {
                select.value = '0';
            }
        });
    };

    if (chamber) {
        chamber.addEventListener('change', () => {
            filterSelect(sector, 'chamber', chamber.value);
        });
    }

    if (species) {
        species.addEventListener('change', () => {
            filterSelect(size, 'species', species.value);
        });
    }

    if (group && groupForm) {
        group.addEventListener('change', () => {
            groupForm.submit();
        });
    }

    filterSelect(sector, 'chamber', chamber ? chamber.value : 0);
    filterSelect(size, 'species', species ? species.value : 0);
})();

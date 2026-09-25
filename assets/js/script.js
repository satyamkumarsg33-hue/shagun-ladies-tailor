function getSliderStep() {
    const slider = document.getElementById("slider");
    const firstCard = slider ? slider.querySelector(".slide-card") : null;

    if (!slider || !firstCard) {
        return 300;
    }

    const gap = parseInt(window.getComputedStyle(slider).gap, 10) || 0;
    return firstCard.offsetWidth + gap;
}

function slideLeft() {
    const slider = document.getElementById("slider");
    if (slider) {
        slider.scrollLeft -= getSliderStep();
    }
}

function slideRight() {
    const slider = document.getElementById("slider");
    if (slider) {
        slider.scrollLeft += getSliderStep();
    }
}

const menuToggle = document.querySelector(".menu-toggle");
const mobileMenu = document.getElementById("mobile-menu");
const menuCloseTargets = document.querySelectorAll("[data-menu-close]");
let lockedScrollY = 0;

function setMenuState(isOpen) {
    if (isOpen) {
        lockedScrollY = window.scrollY || window.pageYOffset || 0;
        document.body.classList.add("menu-open");
        document.body.style.position = "fixed";
        document.body.style.top = "-" + lockedScrollY + "px";
        document.body.style.left = "0";
        document.body.style.right = "0";
    } else {
        document.body.classList.remove("menu-open");
        document.body.style.position = "";
        document.body.style.top = "";
        document.body.style.left = "";
        document.body.style.right = "";
        window.scrollTo(0, lockedScrollY);
    }

    if (menuToggle) {
        menuToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
    }

    if (mobileMenu) {
        mobileMenu.setAttribute("aria-hidden", isOpen ? "false" : "true");
    }
}

if (menuToggle && mobileMenu) {
    menuToggle.addEventListener("click", function () {
        const isOpen = document.body.classList.contains("menu-open");
        setMenuState(!isOpen);
    });

    menuCloseTargets.forEach(function (target) {
        target.addEventListener("click", function () {
            setMenuState(false);
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            setMenuState(false);
        }
    });
}

const reviewReadMoreButtons = document.querySelectorAll(".review-read-more");

reviewReadMoreButtons.forEach(function (button) {
    button.addEventListener("click", function () {
        const reviewItem = button.closest(".review-item");

        if (!reviewItem || window.innerWidth > 700) {
            return;
        }

        const isExpanded = reviewItem.classList.toggle("is-expanded");
        button.textContent = isExpanded ? "Read less" : "Read more";
        button.setAttribute("aria-expanded", isExpanded ? "true" : "false");
    });
});

const whyChooseToggle = document.querySelector(".why-choose-toggle");

if (whyChooseToggle) {
    whyChooseToggle.addEventListener("click", function () {
        const whyChoosePanel = whyChooseToggle.closest(".why-choose-panel");

        if (!whyChoosePanel || window.innerWidth > 700) {
            return;
        }

        const isExpanded = whyChoosePanel.classList.toggle("is-expanded");
        whyChooseToggle.textContent = isExpanded ? "Show less" : "Show more";
        whyChooseToggle.setAttribute("aria-expanded", isExpanded ? "true" : "false");
    });
}

const faqToggle = document.querySelector(".faq-toggle");

if (faqToggle) {
    faqToggle.addEventListener("click", function () {
        const faqPanel = faqToggle.closest(".faq-panel");

        if (!faqPanel || window.innerWidth > 700) {
            return;
        }

        const isExpanded = faqPanel.classList.toggle("is-expanded");
        faqToggle.textContent = isExpanded ? "Show less" : "Show more";
        faqToggle.setAttribute("aria-expanded", isExpanded ? "true" : "false");
    });
}

// LIGHTBOX
const images = document.querySelectorAll(".gallery-item img");
const lightbox = document.getElementById("lightbox");
const lightboxImg = document.getElementById("lightbox-img");
const closeBtn = document.querySelector(".close");
const lightboxTitle = document.getElementById("lightbox-title");
const lightboxDescription = document.getElementById("lightbox-description");
const lightboxLink = document.getElementById("lightbox-link");
const lightboxDetailsClose = document.getElementById("lightbox-details-close");
const lightboxViewer = document.querySelector(".lightbox-viewer");
const lightboxZoomLens = document.getElementById("lightbox-zoom-lens");
const lightboxZoomPreview = document.getElementById("lightbox-zoom-preview");
let lightboxDetailsTimer;
let lightboxScrollY = 0;

const galleryDetails = {
    blouse: {
        title: "The Blouse Edit",
        description: "Refined blouse silhouettes, designed around your fit, neckline, and occasion.",
        href: "standard-stitching.php"
    },
    kurti: {
        title: "The Kurti Edit",
        description: "Effortless custom kurtis with clean tailoring and comfortable everyday elegance.",
        href: "standard-stitching.php"
    },
    lehenga: {
        title: "The Lehenga Edit",
        description: "Celebration-ready lehenga styles finished with thoughtful detailing and a graceful fit.",
        href: "standard-stitching.php"
    },
    handwork: {
        title: "The Handcrafted Edit",
        description: "Intricate handwork created for distinctive festive, bridal, and heirloom looks.",
        href: "index.php#hand-work"
    },
    machinework: {
        title: "The Machine Work Edit",
        description: "Precise machine detailing for elegant designs with a polished, consistent finish.",
        href: "index.php#machine-work"
    }
};

function resetProductZoom() {
    if (!lightboxZoomLens || !lightboxZoomPreview) {
        return;
    }

    lightboxZoomLens.classList.remove("is-visible");
    lightboxZoomPreview.classList.remove("is-visible");
}

function updateProductZoom(event) {
    if (!lightboxImg || !lightboxViewer || !lightboxZoomLens || !lightboxZoomPreview || !lightboxImg.naturalWidth) {
        return;
    }

    const imageBox = lightboxImg.getBoundingClientRect();
    const imageRatio = lightboxImg.naturalWidth / lightboxImg.naturalHeight;
    const boxRatio = imageBox.width / imageBox.height;
    const displayedWidth = imageRatio > boxRatio ? imageBox.width : imageBox.height * imageRatio;
    const displayedHeight = imageRatio > boxRatio ? imageBox.width / imageRatio : imageBox.height;
    const offsetX = (imageBox.width - displayedWidth) / 2;
    const offsetY = (imageBox.height - displayedHeight) / 2;
    const x = event.clientX - imageBox.left - offsetX;
    const y = event.clientY - imageBox.top - offsetY;

    if (x < 0 || y < 0 || x > displayedWidth || y > displayedHeight) {
        resetProductZoom();
        return;
    }

    const lensSize = Math.min(140, displayedWidth * 0.34, displayedHeight * 0.34);
    const lensX = Math.max(0, Math.min(x - lensSize / 2, displayedWidth - lensSize));
    const lensY = Math.max(0, Math.min(y - lensSize / 2, displayedHeight - lensSize));
    const positionX = (x / displayedWidth) * 100;
    const positionY = (y / displayedHeight) * 100;

    lightboxZoomLens.style.width = lensSize + "px";
    lightboxZoomLens.style.height = lensSize + "px";
    lightboxZoomLens.style.left = offsetX + lensX + "px";
    lightboxZoomLens.style.top = offsetY + lensY + "px";
    lightboxZoomPreview.style.backgroundImage = "url('" + lightboxImg.currentSrc + "')";
    lightboxZoomPreview.style.backgroundSize = "270%";
    lightboxZoomPreview.style.backgroundPosition = positionX + "% " + positionY + "%";
    lightboxZoomLens.classList.add("is-visible");
    lightboxZoomPreview.classList.add("is-visible");
}

function lockLightboxScroll() {
    lightboxScrollY = window.scrollY || window.pageYOffset || 0;
    document.body.classList.add("lightbox-open");
    document.body.style.position = "fixed";
    document.body.style.top = "-" + lightboxScrollY + "px";
    document.body.style.left = "0";
    document.body.style.right = "0";
    document.body.style.width = "100%";
}

function unlockLightboxScroll() {
    document.body.classList.remove("lightbox-open");
    document.body.style.position = "";
    document.body.style.top = "";
    document.body.style.left = "";
    document.body.style.right = "";
    document.body.style.width = "";
    window.scrollTo(0, lightboxScrollY);
}

function closeLightbox() {
    if (!lightbox) {
        return;
    }

    clearTimeout(lightboxDetailsTimer);
    lightbox.classList.remove("is-details-visible");
    lightbox.style.display = "none";
    resetProductZoom();
    unlockLightboxScroll();
}

if (lightbox && lightboxImg) {
    images.forEach(img => {
        img.addEventListener("click", () => {
            const galleryItem = img.closest(".gallery-item");
            const category = galleryItem ? galleryItem.dataset.category : "blouse";
            const details = galleryDetails[category] || galleryDetails.blouse;

            clearTimeout(lightboxDetailsTimer);
            lightbox.classList.remove("is-details-visible");
            lockLightboxScroll();
            lightbox.style.display = "flex";
            lightboxImg.src = img.src;
            lightboxImg.alt = img.alt;
            resetProductZoom();

            if (lightboxTitle) {
                lightboxTitle.textContent = details.title;
            }
            if (lightboxDescription) {
                lightboxDescription.textContent = details.description;
            }
            if (lightboxLink) {
                lightboxLink.href = details.href;
            }
            const lightboxLuxeBtn = document.getElementById("lightbox-luxe-btn");
            if (lightboxLuxeBtn) {
                if (galleryItem && galleryItem.dataset.luxeSelectUrl) {
                    lightboxLuxeBtn.href = galleryItem.dataset.luxeSelectUrl;
                    lightboxLuxeBtn.style.display = "inline-block";
                } else {
                    lightboxLuxeBtn.style.display = "none";
                }
            }
            if (lightbox) {
                lightbox.style.setProperty("--design-image", "url('" + img.src + "')");
            }

            lightboxDetailsTimer = setTimeout(() => {
                lightbox.classList.add("is-details-visible");
            }, 400);
        });
    });
}

if (closeBtn && lightbox) {
    closeBtn.addEventListener("click", closeLightbox);
}

if (lightboxImg) {
    lightboxImg.addEventListener("pointerdown", updateProductZoom);
    lightboxImg.addEventListener("pointermove", updateProductZoom);
    lightboxImg.addEventListener("pointerleave", (event) => {
        if (event.pointerType !== "touch") {
            resetProductZoom();
        }
    });
}

if (lightboxDetailsClose && lightbox) {
    lightboxDetailsClose.addEventListener("click", () => {
        clearTimeout(lightboxDetailsTimer);
        lightbox.classList.remove("is-details-visible");
    });
}

if (lightbox) {
    lightbox.addEventListener("click", (event) => {
        if (event.target === lightbox) {
            closeLightbox();
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeLightbox();
        }
    });
}

const filterBtns = document.querySelectorAll(".filter-btn");
const items = document.querySelectorAll(".gallery-item");
const galleryGrid = document.querySelector(".gallery-grid");

filterBtns.forEach(btn => {
    btn.addEventListener("click", () => {
        const filter = btn.getAttribute("data-filter");

        filterBtns.forEach(b => b.classList.remove("active"));
        btn.classList.add("active");

        if (galleryGrid) {
            galleryGrid.classList.toggle("is-filtered", filter !== "all");
        }

        items.forEach(item => {
            const shouldShow = filter === "all" || item.dataset.category === filter || item.classList.contains(filter);
            item.style.display = shouldShow ? "" : "none";
        });
    });
});

const gallerySec = document.querySelector(".gallery[data-initial-category]");
if (gallerySec) {
    const initCat = gallerySec.dataset.initialCategory;
    if (initCat && initCat !== "all") {
        const initBtn = document.querySelector(`.filter-btn[data-filter="${initCat}"]`);
        if (initBtn) {
            initBtn.click();
        }
    }
}


const waIcon = document.getElementById("waIcon");
const waCard = document.getElementById("waCard");
const waClose = document.getElementById("waClose");
const waDismiss = document.getElementById("waDismiss");

// icon click → open card
if (waIcon) {
    waIcon.addEventListener("click", () => {
        if (waCard) waCard.style.display = "block";
    });
}

// close button
if (waClose) {
    waClose.addEventListener("click", () => {
        if (waCard) waCard.style.display = "none";
        localStorage.setItem("waClosed", "true");
    });
}

if (waDismiss) {
    waDismiss.addEventListener("click", () => {
        waCard.style.display = "none";
        document.querySelector(".whatsapp-container").style.display = "none";
        localStorage.setItem("waDismissed", "true");
    });
}

if (localStorage.getItem("waDismissed")) {
    document.querySelector(".whatsapp-container").style.display = "none";
}

// auto open after delay
setTimeout(() => {
    if (!localStorage.getItem("waClosed")) {
        waCard.style.display = "block";
    }
}, 60000); // 60 sec (change to 120000 for 2 min)



/* =========================================================
   SHAGUN LUXE — PEOPLE / WEDDING STEP 2
   ========================================================= */

document.addEventListener("DOMContentLoaded", function () {

    const peopleList = document.getElementById("people-list");
    const peopleForm = document.getElementById("people-form");
    const addPersonButton = document.getElementById("add-person");
    const continueButton = peopleForm ? peopleForm.querySelector("#continue-garments") : null;

    /*
     * This page-specific code should do nothing
     * on other pages.
     */
    if (!peopleList || !peopleForm) {
        return;
    }


    /* ================================
       UPDATE PERSON NUMBERS
    ================================= */

    function updatePersonNumbers() {

        const cards = peopleList.querySelectorAll(".person-card");

        cards.forEach(function (card, index) {

            const number = index + 1;

            const heading = card.querySelector(
                ".person-heading strong"
            );

            if (heading) {
                heading.textContent = "Person " + number;
            }

            const removeButton = card.querySelector(
                "[data-remove-person]"
            );

            /*
             * Person 1 must always remain.
             */
            if (number === 1) {

                if (removeButton) {
                    removeButton.remove();
                }

            } else {

                /*
                 * Make sure every other person
                 * has a close/remove button.
                 */
                if (!removeButton) {

                    const button = document.createElement("button");

                    button.type = "button";
                    button.className = "person-remove";
                    button.setAttribute(
                        "data-remove-person",
                        ""
                    );
                    button.setAttribute(
                        "aria-label",
                        "Remove Person " + number
                    );

                    button.textContent = "×";

                    card.appendChild(button);
                }
            }
        });
    }


    /* ================================
       ADD ANOTHER PERSON
    ================================= */

    addPersonButton.addEventListener("click", function () {

        const number =
            peopleList.querySelectorAll(".person-card").length + 1;

        const card = document.createElement("article");

        card.className = "person-card";

        card.innerHTML = `
            <div class="person-heading">

                <span class="person-icon" aria-hidden="true">
                    ♟
                </span>

                <strong>
                    Person ${number}
                </strong>

            </div>


            <div class="person-fields">
                <input
                    type="hidden"
                    name="person_garments[]"
                    value="[]"
                >

                <div class="person-field">

                    <label>
                        Name <span>*</span>
                    </label>

                    <input
                        type="text"
                        name="person_name[]"
                        placeholder="Enter name"
                        autocomplete="name"
                        required
                    >

                </div>


                <div class="person-field">

                    <label>
                        Relationship / Role
                        <small>(Optional)</small>
                    </label>

                    <input
                        type="text"
                        name="person_role[]"
                        placeholder="e.g. Bride, Sister, Friend"
                    >

                </div>

            </div>


            <div class="person-actions">

                <div class="person-garments">

                    <span aria-hidden="true">
                        ♧
                    </span>

                    <small>
                        0 garments added
                    </small>

                </div>


                <button
                    type="button"
                    class="person-garment-button"
                    data-garment-button
                >
                    Add Garments →
                </button>

            </div>


            <button
                type="button"
                class="person-remove"
                data-remove-person
                aria-label="Remove Person ${number}"
            >
                ×
            </button>
        `;

        peopleList.appendChild(card);

        updatePersonNumbers();

        const nameInput = card.querySelector(
            'input[name="person_name[]"]'
        );

        if (nameInput) {
            nameInput.focus();
        }

        card.scrollIntoView({
            behavior: "smooth",
            block: "center"
        });

    });


    /* ================================
       REMOVE PERSON
    ================================= */

    peopleList.addEventListener("click", function (event) {

        const removeButton =
            event.target.closest("[data-remove-person]");

        if (!removeButton) {
            return;
        }

        const cards =
            peopleList.querySelectorAll(".person-card");

        /*
         * Never allow the final person
         * to be removed.
         */
        if (cards.length <= 1) {

            alert("At least one person is required.");

            return;
        }

        const card =
            removeButton.closest(".person-card");

        if (card) {
            card.remove();
        }

        updatePersonNumbers();

    });


    /* ================================
       VALIDATE PEOPLE
    ================================= */

    function validatePeople() {

        const cards =
            peopleList.querySelectorAll(".person-card");

        if (cards.length === 0) {

            alert("Please add at least one person.");

            return false;
        }

        for (const card of cards) {

            const nameInput =
                card.querySelector(
                    'input[name="person_name[]"]'
                );

            if (
                !nameInput ||
                !nameInput.value.trim()
            ) {

                if (nameInput) {
                    nameInput.focus();
                }

                alert(
                    "Please enter the name for every person."
                );

                return false;
            }
        }

        return true;
    }


    /* ================================
       INDIVIDUAL ADD GARMENTS
    ================================= */

    peopleList.addEventListener("click", function (event) {

        const garmentButton =
            event.target.closest("[data-garment-button]");

        if (!garmentButton) {
            return;
        }

        const card =
            garmentButton.closest(".person-card");

        if (!card) {
            return;
        }

        if (!validatePeople()) {
            return;
        }

        const personNumber =
            Array.from(
                peopleList.querySelectorAll(".person-card")
            ).indexOf(card) + 1;

        let gotoInput = peopleForm.querySelector('input[name="goto_person"]');
        if (!gotoInput) {
            gotoInput = document.createElement("input");
            gotoInput.type = "hidden";
            gotoInput.name = "goto_person";
            peopleForm.appendChild(gotoInput);
        }
        gotoInput.value = personNumber;

        let saveInput = peopleForm.querySelector('input[name="save_people"]');
        if (!saveInput) {
            saveInput = document.createElement("input");
            saveInput.type = "hidden";
            saveInput.name = "save_people";
            saveInput.value = "1";
            peopleForm.appendChild(saveInput);
        }

        peopleForm.submit();

    });


    /* ================================
       CONTINUE → ADD GARMENTS
    ================================= */

    if (continueButton) {

        continueButton.addEventListener("click", function (event) {

            if (!validatePeople()) {
                event.preventDefault();
                return;
            }

        });
    }

    peopleForm.addEventListener("submit", function (event) {

        if (!validatePeople()) {
            event.preventDefault();
            return;
        }

    });


    /* ================================
       INITIAL SETUP
    ================================= */

    updatePersonNumbers();

});


/* =========================================================
   SHAGUN LUXE — GARMENTS / PERSON SELECTION
   ========================================================= */

document.addEventListener("DOMContentLoaded", function () {

    const garmentsPage = document.querySelector(".luxe-garments-page");

    /*
     * This page-specific code should do nothing
     * on other pages.
     */
    if (!garmentsPage) {
        return;
    }

    const garmentCards = garmentsPage.querySelectorAll(".luxe-garment-card");
    const countElement = document.getElementById("selected-garment-count");
    const continueButton = garmentsPage.querySelector("#continue-garments");
    const personTabs = garmentsPage.querySelectorAll(".luxe-person-tab");

    /*
     * Normalize garment counts from either an array or an object of counts.
     */
    function normalizeGarmentCounts(data) {
        const counts = {};
        if (!data) {
            return counts;
        }
        if (Array.isArray(data)) {
            data.forEach(function (item) {
                const name = (typeof item === 'string') ? item.trim() : (item && item.name ? String(item.name).trim() : '');
                if (name) {
                    counts[name] = (counts[name] || 0) + 1;
                }
            });
        } else if (typeof data === 'object') {
            for (const key in data) {
                const val = parseInt(data[key], 10);
                if (val > 0) {
                    counts[key] = val;
                }
            }
        }
        return counts;
    }

    /*
     * Store garment quantities separately for every person.
     *
     * Example:
     * person 1 → { "Blouse": 2, "Saree": 1 }
     * person 2 → { "Blouse": 1, "Lehenga": 2 }
     */
    const garmentsByPerson = {};

    if (garmentsPage.dataset.allGarments) {
        try {
            const allSaved = JSON.parse(garmentsPage.dataset.allGarments);
            if (allSaved && typeof allSaved === "object") {
                for (const pNum in allSaved) {
                    garmentsByPerson[pNum] = normalizeGarmentCounts(allSaved[pNum]);
                }
            }
        } catch (e) {
            // Ignore parse errors
        }
    }

    const activePersonNumber =
        parseInt(
            garmentsPage.dataset.activePerson,
            10
        ) || 1;

    let savedInitialActive = {};
    if (garmentsPage.dataset.selectedGarments) {
        try {
            savedInitialActive = normalizeGarmentCounts(JSON.parse(garmentsPage.dataset.selectedGarments));
        } catch (e) {
            // Ignore
        }
    }

    if (!garmentsByPerson[activePersonNumber] || Object.keys(garmentsByPerson[activePersonNumber]).length === 0) {
        garmentsByPerson[activePersonNumber] = savedInitialActive;
    }

    let activePerson = activePersonNumber;

    personTabs.forEach(function (tab) {
        const pNum = parseInt(tab.dataset.person, 10);
        if (pNum && !garmentsByPerson[pNum]) {
            garmentsByPerson[pNum] = {};
        }
    });

    function getCardGarmentName(card) {
        return (card.dataset.garment || card.querySelector("strong")?.textContent || "").trim();
    }

    /*
     * Get the current garment selections for the active person as a quantity map.
     */
    function getCurrentSelections() {
        const selected = {};

        garmentCards.forEach(function (card) {
            const gName = getCardGarmentName(card);
            const valElem = card.querySelector("[data-qty-display]");
            const qty = parseInt(valElem?.textContent || "0", 10) || 0;

            if (gName && qty > 0) {
                selected[gName] = qty;
            }
        });

        return selected;
    }

    /*
     * Save current person's garment quantities.
     */
    function saveCurrentPerson() {
        garmentsByPerson[activePerson] = getCurrentSelections();
    }

    /*
     * Show the selected garment quantities for a person.
     */
    function loadPerson(personNumber) {
        const personCounts = garmentsByPerson[personNumber] || {};

        garmentCards.forEach(function (card) {
            const gName = getCardGarmentName(card);
            const qty = parseInt(personCounts[gName], 10) || 0;
            const valElem = card.querySelector("[data-qty-display]");

            if (valElem) {
                valElem.textContent = qty;
            }

            card.classList.toggle("is-selected", qty > 0);
        });

        updateGarmentCount();
    }

    /*
     * Update selected garment count and continue button state.
     */
    function updateGarmentCount() {
        let activeCount = 0;

        garmentCards.forEach(function (card) {
            const valElem = card.querySelector("[data-qty-display]");
            const qty = parseInt(valElem?.textContent || "0", 10) || 0;
            activeCount += qty;
            card.classList.toggle("is-selected", qty > 0);
        });

        if (countElement) {
            countElement.textContent = activeCount;
        }

        /*
         * Check total garments across ALL people.
         */
        let totalAll = activeCount;
        for (const p in garmentsByPerson) {
            if (parseInt(p, 10) !== activePerson) {
                const personCounts = garmentsByPerson[p];
                if (personCounts && typeof personCounts === "object") {
                    for (const g in personCounts) {
                        totalAll += (parseInt(personCounts[g], 10) || 0);
                    }
                }
            }
        }

        if (continueButton) {
            continueButton.disabled = (totalAll === 0);
        }
    }

    /*
     * Garment quantity controls and card interactions.
     */
    garmentCards.forEach(function (card) {
        const minusBtn = card.querySelector('[data-qty-action="minus"]');
        const plusBtn = card.querySelector('[data-qty-action="plus"]');
        const valElem = card.querySelector("[data-qty-display]");

        function setCardQty(newQty) {
            const clamped = Math.max(0, parseInt(newQty, 10) || 0);
            if (valElem) {
                valElem.textContent = clamped;
            }
            card.classList.toggle("is-selected", clamped > 0);
            updateGarmentCount();
            saveCurrentPerson();
        }

        if (plusBtn) {
            plusBtn.addEventListener("click", function (event) {
                event.stopPropagation();
                const cur = parseInt(valElem?.textContent || "0", 10) || 0;
                setCardQty(cur + 1);
            });
        }

        if (minusBtn) {
            minusBtn.addEventListener("click", function (event) {
                event.stopPropagation();
                const cur = parseInt(valElem?.textContent || "0", 10) || 0;
                setCardQty(cur - 1);
            });
        }

        // Tap/click card: if qty is 0, select with quantity 1
        card.addEventListener("click", function (event) {
            // Ignore if clicked on buttons
            if (event.target.closest(".luxe-qty-btn")) {
                return;
            }
            const cur = parseInt(valElem?.textContent || "0", 10) || 0;
            if (cur === 0) {
                setCardQty(1);
            }
        });
    });

    /*
     * Switch between people tabs.
     */
    personTabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
            saveCurrentPerson();

            personTabs.forEach(function (item) {
                item.classList.remove("is-active");
            });

            this.classList.add("is-active");

            activePerson = parseInt(this.dataset.person, 10) || 1;

            const introName = document.querySelector(".luxe-person-selector-intro strong");
            const introRole = document.querySelector(".luxe-person-selector-intro small");
            if (introName) {
                introName.textContent = this.querySelector("strong")?.textContent.trim() || ("Person " + activePerson);
            }
            if (introRole) {
                introRole.textContent = this.querySelector("small")?.textContent.trim() || "";
            }

            loadPerson(activePerson);
        });
    });

    /*
     * SAVE ALL PEOPLE'S GARMENTS
     */
    if (continueButton) {
        continueButton.addEventListener("click", function (event) {
            event.preventDefault();

            saveCurrentPerson();

            personTabs.forEach(function (tab) {
                const pNum = parseInt(tab.dataset.person, 10);
                if (pNum && !garmentsByPerson[pNum]) {
                    garmentsByPerson[pNum] = {};
                }
            });

            const form = document.createElement("form");
            form.method = "POST";
            form.action = "garments.php";

            const saveField = document.createElement("input");
            saveField.type = "hidden";
            saveField.name = "save_all_garments";
            saveField.value = "1";
            form.appendChild(saveField);

            const jsonField = document.createElement("input");
            jsonField.type = "hidden";
            jsonField.name = "garments_by_person";
            jsonField.value = JSON.stringify(garmentsByPerson);
            form.appendChild(jsonField);

            // Also provide array inputs with duplicate names for each individual garment
            for (const personNum in garmentsByPerson) {
                const personCounts = garmentsByPerson[personNum];
                if (personCounts && typeof personCounts === "object") {
                    for (const gName in personCounts) {
                        const count = parseInt(personCounts[gName], 10) || 0;
                        for (let i = 0; i < count; i++) {
                            const garmentField = document.createElement("input");
                            garmentField.type = "hidden";
                            garmentField.name = "people_garments[" + personNum + "][]";
                            garmentField.value = gName;
                            form.appendChild(garmentField);
                        }
                    }
                }
            }

            document.body.appendChild(form);
            form.submit();
        });
    }

    /*
     * Load active person's garments initially.
     */
    loadPerson(activePerson);

});


/* =========================================================
   LUXE ORDER WORKSPACE
   ========================================================= */

document.addEventListener('DOMContentLoaded', function () {

    const workspace = document.querySelector('[data-luxe-workspace]');

    if (!workspace) {
        return;
    }


    /* -----------------------------------------------------
       VIEW DETAILS
    ----------------------------------------------------- */

    const detailButtons =
        workspace.querySelectorAll('[data-view-details]');

    detailButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const card =
                button.closest('[data-garment-card]');

            if (!card) {
                return;
            }

            const status =
                card.querySelector('.luxe-garment-status');

            const statusText =
                status ? status.textContent.trim() : 'Not started';

            alert(
                'Garment details\n\n' +
                'Status: ' + statusText +
                '\n\nThe detailed customization summary will appear here.'
            );

        });

    });


    /* -----------------------------------------------------
       NON-BLOUSE DEMO BUTTONS
    ----------------------------------------------------- */

    const unavailableButtons =
        workspace.querySelectorAll('[data-demo-unavailable]');

    unavailableButtons.forEach(function (button) {

        button.addEventListener('click', function (event) {

            event.preventDefault();

            alert(
                'This garment flow is coming next.\n\n' +
                'The Luxe Workspace is ready for the garment-specific ' +
                'customization flows to be connected.'
            );

        });

    });


    /* -----------------------------------------------------
       MARK COMPLETED GARMENT INCOMPLETE
    ----------------------------------------------------- */

    const incompleteButtons =
        workspace.querySelectorAll('[data-toggle-incomplete]');

    incompleteButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const card =
                button.closest('[data-garment-card]');

            if (!card) {
                return;
            }

            const statusBadge =
                card.querySelector('.luxe-garment-status');

            const customDetails =
                card.querySelector('.luxe-customization-details');

            if (customDetails) {
                customDetails.remove();
            }

            let description =
                card.querySelector('.luxe-workspace-garment-info > p');

            if (statusBadge) {
                statusBadge.textContent = 'In progress';

                statusBadge.classList.remove(
                    'status-completed'
                );

                statusBadge.classList.add(
                    'status-in-progress'
                );
            }

            card.classList.remove(
                'status-completed'
            );

            card.classList.add(
                'status-in-progress'
            );

            if (!description) {
                description = document.createElement('p');
                const actions = card.querySelector('.luxe-workspace-garment-actions');
                if (actions) {
                    actions.before(description);
                }
            }

            if (description) {
                description.textContent =
                    'Your customization has been started. Continue where you left off.';
            }

            button.remove();

            const actions =
                card.querySelector('.luxe-workspace-garment-actions');

            if (actions) {
                const existingEditLink = actions.querySelector('a');
                const targetUrl = existingEditLink ? existingEditLink.getAttribute('href') : '#';

                const continueLink =
                    document.createElement('a');

                continueLink.href = targetUrl;

                continueLink.className =
                    'luxe-workspace-primary-action';

                continueLink.textContent =
                    'Continue Customizing';

                actions.prepend(continueLink);
            }

            updateWorkspaceProgress();

        });

    });


    /* -----------------------------------------------------
       NEXT ITEM
    ----------------------------------------------------- */

    const nextButton =
        workspace.querySelector('[data-next-item]');

    if (nextButton) {

        nextButton.addEventListener('click', function () {

            const nextCard =
                workspace.querySelector(
                    '[data-garment-card].status-not-started, ' +
                    '[data-garment-card].status-in-progress'
                );

            if (!nextCard) {
                return;
            }

            nextCard.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });

            nextCard.classList.add(
                'luxe-workspace-focus'
            );

            window.setTimeout(function () {

                nextCard.classList.remove(
                    'luxe-workspace-focus'
                );

            }, 1400);

        });

    }


    /* -----------------------------------------------------
       PROCEED TO MEASUREMENTS
    ----------------------------------------------------- */

    const measurementsButton =
        workspace.querySelector('[data-proceed-measurements]');

    if (measurementsButton) {

        measurementsButton.addEventListener('click', function (event) {

            if (
                measurementsButton.classList.contains(
                    'is-disabled'
                )
            ) {

                event.preventDefault();

                alert(
                    'Please complete at least one garment before proceeding to measurements.'
                );

                return;
            }

            // When enabled, allow natural browser navigation to measurements.php

        });

    }


    /* -----------------------------------------------------
       PROGRESS REFRESH
    ----------------------------------------------------- */

    function updateWorkspaceProgress() {

        const cards =
            workspace.querySelectorAll(
                '[data-garment-card]'
            );

        let total = cards.length;
        let completed = 0;
        let inProgress = 0;

        cards.forEach(function (card) {

            if (
                card.classList.contains(
                    'status-completed'
                )
            ) {
                completed++;
            }

            if (
                card.classList.contains(
                    'status-in-progress'
                )
            ) {
                inProgress++;
            }

        });

        const notStarted =
            Math.max(0, total - completed - inProgress);

        const percentage =
            total > 0
                ? Math.round((completed / total) * 100)
                : 0;


        const progressBar =
            workspace.querySelector(
                '.luxe-progress-bar span'
            );

        if (progressBar) {
            progressBar.style.width =
                percentage + '%';
        }


        const progressPercent =
            workspace.querySelector(
                '.luxe-progress-percent'
            );

        if (progressPercent) {
            progressPercent.textContent =
                percentage + '% completed';
        }


        const stats =
            workspace.querySelectorAll(
                '.luxe-progress-stats strong'
            );

        if (stats.length >= 3) {
            stats[0].textContent = completed;
            stats[1].textContent = inProgress;
            stats[2].textContent = notStarted;
        }


        const progressHeading =
            workspace.querySelector(
                '.luxe-progress-card-heading > strong'
            );

        if (progressHeading) {
            progressHeading.textContent =
                completed + '/' + total;
        }

        const measurementsBtn =
            workspace.querySelector('[data-proceed-measurements]');
        const bottomNote =
            workspace.querySelector('.luxe-workspace-bottom-note');

        if (measurementsBtn) {
            if (completed >= 1) {
                measurementsBtn.classList.remove('is-disabled');
            } else {
                measurementsBtn.classList.add('is-disabled');
            }
        }

        if (bottomNote) {
            if (completed >= 1) {
                bottomNote.classList.add('is-ready');
                bottomNote.textContent =
                    "Continue to Measurements whenever you're ready";
            } else {
                bottomNote.classList.remove('is-ready');
                bottomNote.textContent =
                    'Complete at least one garment to continue';
            }
        }

    }

});


/* =========================================================
   SHAGUN LUXE — WORK TYPE SELECTION (luxe-work.php)
   ========================================================= */

document.addEventListener('DOMContentLoaded', function () {

    const workPage = document.querySelector('[data-luxe-work-page]');

    if (!workPage) {
        return;
    }

    const workRadios = workPage.querySelectorAll('[data-work-radio]');
    const machinePanel = document.getElementById('panel-machine-work');
    const handPanel = document.getElementById('panel-hand-work');

    const summaryMachineRow = document.getElementById('summary-machine-row');
    const summaryMachineVal = document.getElementById('summary-machine-val');
    const summaryHandRow = document.getElementById('summary-hand-row');
    const summaryHandVal = document.getElementById('summary-hand-val');
    const summaryTotalVal = document.getElementById('summary-total-val');

    const basePrice = parseInt(workPage.dataset.basePrice, 10) || 0;
    const customPrice = parseInt(workPage.dataset.customPrice, 10) || 0;

    let currentWorkType = workPage.querySelector('[data-work-radio]:checked')?.value || workPage.dataset.initialWorkType || 'no_work';

    function updateLivePrice() {
        let machinePrice = 0;
        let handPrice = 0;

        if (currentWorkType === 'machine' || currentWorkType === 'both') {
            const checkedM = machinePanel ? machinePanel.querySelector('input[name="machine_design"]:checked') : null;
            machinePrice = checkedM ? (parseInt(checkedM.dataset.price, 10) || 350) : 350;
            if (summaryMachineRow && summaryMachineVal) {
                summaryMachineRow.style.display = 'flex';
                summaryMachineVal.textContent = '+₹' + machinePrice.toLocaleString();
            }
        } else {
            if (summaryMachineRow) {
                summaryMachineRow.style.display = 'none';
            }
        }

        if (currentWorkType === 'hand' || currentWorkType === 'both') {
            const checkedH = handPanel ? handPanel.querySelector('input[name="hand_design"]:checked') : null;
            handPrice = checkedH ? (parseInt(checkedH.dataset.price, 10) || 800) : 800;
            if (summaryHandRow && summaryHandVal) {
                summaryHandRow.style.display = 'flex';
                summaryHandVal.textContent = '+₹' + handPrice.toLocaleString();
            }
        } else {
            if (summaryHandRow) {
                summaryHandRow.style.display = 'none';
            }
        }

        const total = basePrice + customPrice + machinePrice + handPrice;
        if (summaryTotalVal) {
            summaryTotalVal.textContent = '₹' + total.toLocaleString();
        }
    }

    function setWorkType(newType) {
        currentWorkType = newType;

        workRadios.forEach(function (radio) {
            const card = radio.closest('.luxe-work-type-card');
            const isMatch = (radio.value === newType);
            radio.checked = isMatch;
            if (card) {
                card.classList.toggle('is-selected', isMatch);
            }
        });

        if (machinePanel) {
            machinePanel.style.display = (newType === 'machine' || newType === 'both') ? 'block' : 'none';
        }

        if (handPanel) {
            handPanel.style.display = (newType === 'hand' || newType === 'both') ? 'block' : 'none';
        }

        const machineBanner = workPage.querySelector('[data-selected-banner="machine"]');
        if (machineBanner) {
            machineBanner.style.display = (newType === 'machine' || newType === 'both') ? '' : 'none';
        }

        const handBanner = workPage.querySelector('[data-selected-banner="hand"]');
        if (handBanner) {
            handBanner.style.display = (newType === 'hand' || newType === 'both') ? '' : 'none';
        }

        updateLivePrice();
    }

    workRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            const chosenType = this.value;

            // Destructive change confirmations
            if (currentWorkType === 'both' && chosenType === 'machine') {
                const confirmed = window.confirm('Changing the work type will remove the existing Hand Work details. Continue?');
                if (!confirmed) {
                    setWorkType('both');
                    return;
                }
            } else if (currentWorkType === 'both' && chosenType === 'hand') {
                const confirmed = window.confirm('Changing the work type will remove the existing Machine Work details. Continue?');
                if (!confirmed) {
                    setWorkType('both');
                    return;
                }
            } else if (currentWorkType !== 'no_work' && chosenType === 'no_work') {
                const confirmed = window.confirm('Changing to No Work will remove the selected embroidery details. Continue?');
                if (!confirmed) {
                    setWorkType(currentWorkType);
                    return;
                }
            }

            setWorkType(chosenType);
        });
    });

    function updateSelectedBanner(type, code, name, price) {
        const banner = workPage.querySelector('[data-selected-banner="' + type + '"]');
        if (!banner) return;
        const titleEl = banner.querySelector('[data-selected-title="' + type + '"]');
        const priceEl = banner.querySelector('[data-selected-price="' + type + '"]');
        if (titleEl) titleEl.textContent = code + ' — ' + name;
        if (priceEl) priceEl.textContent = '+₹' + parseInt(price, 10).toLocaleString();
        banner.style.display = '';
    }

    // Design Card selection in panels
    const designCards = workPage.querySelectorAll('[data-design-card]');
    designCards.forEach(function (card) {
        card.addEventListener('click', function () {
            const radio = card.querySelector('input[type="radio"]');
            if (radio && !radio.checked) {
                radio.checked = true;
                const type = card.dataset.workType;
                const grid = card.closest('[data-design-grid]');
                if (grid) {
                    const siblings = grid.querySelectorAll('[data-design-card]');
                    siblings.forEach(function (sib) {
                        sib.classList.remove('is-selected');
                        const pill = sib.querySelector('.luxe-design-select-pill');
                        if (pill) pill.textContent = 'Select';
                    });
                }
                card.classList.add('is-selected');
                const pill = card.querySelector('.luxe-design-select-pill');
                if (pill) pill.textContent = 'Selected ✓';

                if (type && card.dataset.code && card.dataset.name) {
                    updateSelectedBanner(type, card.dataset.code, card.dataset.name, card.dataset.price || 0);
                }

                updateLivePrice();
            }
        });
    });

    // Placement Pills selection
    const placementPills = workPage.querySelectorAll('.luxe-placement-pill');
    placementPills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            const radio = pill.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
                const siblings = pill.parentElement.querySelectorAll('.luxe-placement-pill');
                siblings.forEach(function (sib) {
                    sib.classList.remove('is-selected');
                });
                pill.classList.add('is-selected');
            }
        });
    });

    // Setup client-side search for Machine Work and Hand Work
    function setupDesignSearch(type) {
        const searchInput = workPage.querySelector('[data-work-search="' + type + '"]');
        const clearBtn = workPage.querySelector('[data-search-clear="' + type + '"]');
        const grid = workPage.querySelector('[data-design-grid="' + type + '"]');
        const heading = workPage.querySelector('[data-catalogue-heading="' + type + '"]');
        const emptyState = workPage.querySelector('[data-search-empty="' + type + '"]');
        const viewAllRow = workPage.querySelector('[data-view-all-row="' + type + '"]');
        const changeBtn = workPage.querySelector('[data-change-design="' + type + '"]');

        if (!searchInput || !grid) return;

        const cards = Array.from(grid.querySelectorAll('[data-design-card][data-work-type="' + type + '"]'));

        function filterDesigns() {
            const query = searchInput.value.trim().toLowerCase();

            if (clearBtn) {
                clearBtn.style.display = query.length > 0 ? 'inline-flex' : 'none';
            }

            if (query.length === 0) {
                // Restore popular 3 cards (plus currently selected card if not in top 3)
                cards.forEach(function (card) {
                    const isPopular = card.dataset.popular === '1';
                    const isSelected = card.classList.contains('is-selected');
                    card.style.display = (isPopular || isSelected) ? '' : 'none';
                });

                if (heading) {
                    heading.textContent = (type === 'machine') ? 'Popular Machine Work Designs' : 'Popular Hand Work Designs';
                    heading.style.display = '';
                }
                if (emptyState) emptyState.style.display = 'none';
                if (viewAllRow) viewAllRow.style.display = '';
                return;
            }

            // Search filtering by exact or partial code, and exact or partial name
            let matchCount = 0;
            cards.forEach(function (card) {
                const code = (card.dataset.code || '').toLowerCase();
                const name = (card.dataset.name || '').toLowerCase();
                const isMatch = code.indexOf(query) !== -1 || name.indexOf(query) !== -1;

                if (isMatch) {
                    card.style.display = '';
                    matchCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (matchCount > 0) {
                if (heading) {
                    heading.textContent = 'Search Results';
                    heading.style.display = '';
                }
                if (emptyState) emptyState.style.display = 'none';
                if (viewAllRow) viewAllRow.style.display = '';
            } else {
                if (heading) heading.style.display = 'none';
                if (emptyState) emptyState.style.display = 'block';
                if (viewAllRow) viewAllRow.style.display = 'none';
            }
        }

        searchInput.addEventListener('input', filterDesigns);

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                searchInput.value = '';
                filterDesigns();
                searchInput.focus();
            });
        }

        if (changeBtn) {
            changeBtn.addEventListener('click', function () {
                searchInput.focus();
                searchInput.select();
                const panel = searchInput.closest('.luxe-work-panel');
                if (panel) {
                    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        }
    }

    setupDesignSearch('machine');
    setupDesignSearch('hand');

    // Initialize display and pricing
    setWorkType(currentWorkType);

});


/* =========================================================
   SHAGUN LUXE — MEASUREMENTS STEP (measurements.php)
   ========================================================= */

document.addEventListener('DOMContentLoaded', function () {

    const measurementsPage = document.querySelector('[data-measurements-page]');

    if (!measurementsPage) {
        return;
    }

    const personCards = measurementsPage.querySelectorAll('[data-person-card]');

    personCards.forEach(function (personCard) {
        const methodCards = personCard.querySelectorAll('[data-method-card]');
        const statusBadge = personCard.querySelector('[data-status-badge]');
        const contextualPanel = personCard.querySelector('[data-contextual-panel]');
        const panelContents = personCard.querySelectorAll('[data-panel-type]');

        function selectMethod(method) {
            // Update cards
            methodCards.forEach(function (card) {
                const radio = card.querySelector('input[type="radio"]');
                const isMatch = card.dataset.methodCard === method;

                if (isMatch) {
                    card.classList.add('is-selected');
                    if (radio) radio.checked = true;
                } else {
                    card.classList.remove('is-selected');
                }
            });

            // Clear needed notice and highlight
            personCard.classList.remove('is-needed');
            const neededNotice = personCard.querySelector('.luxe-measurement-needed-notice');
            if (neededNotice) {
                neededNotice.style.display = 'none';
            }

            // Update status badge
            if (statusBadge) {
                statusBadge.classList.remove('is-unselected');
                statusBadge.classList.remove('is-needed');
                statusBadge.classList.add('is-selected');

                const desktopText = statusBadge.querySelector('.badge-text-desktop');
                const mobileText = statusBadge.querySelector('.badge-text-mobile');
                if (desktopText) desktopText.textContent = 'Measurement selected';
                if (mobileText) mobileText.textContent = 'Selected';
            }

            // Update contextual panel
            if (contextualPanel) {
                contextualPanel.style.display = '';

                panelContents.forEach(function (panel) {
                    if (panel.dataset.panelType === method) {
                        panel.style.display = '';
                    } else {
                        panel.style.display = 'none';
                    }
                });
            }
        }

        methodCards.forEach(function (card) {
            card.addEventListener('click', function (e) {
                const method = card.dataset.methodCard;
                if (method) {
                    selectMethod(method);
                }
            });

            const radio = card.querySelector('input[type="radio"]');
            if (radio) {
                radio.addEventListener('change', function () {
                    selectMethod(radio.value);
                });
            }
        });
    });

    // Copy Address Button Functionality
    const copyButtons = measurementsPage.querySelectorAll('[data-copy-btn]');

    copyButtons.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const textToCopy = btn.dataset.copyText || '';
            if (!textToCopy) return;

            function showSuccess() {
                const origHtml = btn.innerHTML;
                btn.innerHTML = '<span>✓</span> <span class="btn-text-desktop">Copied!</span><span class="btn-text-mobile">Copied!</span>';
                btn.classList.add('is-copied');

                setTimeout(function () {
                    btn.innerHTML = origHtml;
                    btn.classList.remove('is-copied');
                }, 2000);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(showSuccess).catch(function () {
                    fallbackCopy(textToCopy, showSuccess);
                });
            } else {
                fallbackCopy(textToCopy, showSuccess);
            }
        });
    });

    function fallbackCopy(text, callback) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            if (callback) callback();
        } catch (err) {
            console.error('Copy failed', err);
        }
        document.body.removeChild(textarea);
    }

});


/* =========================================================
   SHAGUN LUXE — REVIEW & PAYMENT STEP (review-payment.php)
   ========================================================= */

document.addEventListener('DOMContentLoaded', function () {

    const reviewPage = document.querySelector('[data-review-payment-page]');
    if (!reviewPage) {
        return;
    }

    const grandTotal = parseInt(reviewPage.dataset.grandTotal, 10) || 0;
    const minAdvance = parseInt(reviewPage.dataset.minAdvance, 10) || Math.ceil(grandTotal * 0.30);
    const maxAdvance = grandTotal;

    const slider = reviewPage.querySelector('#advance-slider');
    const numInput = reviewPage.querySelector('#advance-input');
    const presetBtns = reviewPage.querySelectorAll('.luxe-quick-btn');
    const payNowVal = reviewPage.querySelector('#pay-now-val');
    const remainingVal = reviewPage.querySelector('#remaining-val');
    const btnAmountDisplay = reviewPage.querySelector('#btn-amount-display');
    const declarationCheckbox = reviewPage.querySelector('#declaration-checkbox');
    const confirmPayBtn = reviewPage.querySelector('#confirm-pay-btn');
    const form = reviewPage.querySelector('#review-payment-form');

    // Toast Notification Elements & State
    const toast = reviewPage.querySelector('[data-luxe-toast]');
    const toastAmountDisplay = toast ? toast.querySelector('#toast-amount-display') : null;
    const closeBtn = toast ? toast.querySelector('#luxe-toast-close') : null;
    let toastTimer = null;
    let toastHideAnimationTimer = null;

    function formatRupee(num) {
        return '₹' + Number(num).toLocaleString('en-IN');
    }

    function showToast() {
        if (!toast) return;

        if (toastTimer) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }
        if (toastHideAnimationTimer) {
            clearTimeout(toastHideAnimationTimer);
            toastHideAnimationTimer = null;
        }

        const currentAmount = parseInt(numInput ? numInput.value : (slider ? slider.value : 0), 10) || minAdvance;
        if (toastAmountDisplay) {
            toastAmountDisplay.textContent = formatRupee(currentAmount);
        }

        toast.classList.remove('is-hiding');
        toast.style.display = 'flex';

        // Restart entry animation
        toast.style.animation = 'none';
        toast.offsetHeight; // trigger reflow
        toast.style.animation = '';

        // Auto-dismiss after approximately 4.5 seconds
        toastTimer = setTimeout(function () {
            hideToast(true);
        }, 4500);
    }

    function hideToast(animate) {
        if (!toast) return;

        if (toastTimer) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }
        if (toastHideAnimationTimer) {
            clearTimeout(toastHideAnimationTimer);
            toastHideAnimationTimer = null;
        }

        if (animate) {
            toast.classList.add('is-hiding');
            toastHideAnimationTimer = setTimeout(function () {
                toast.style.display = 'none';
                toast.classList.remove('is-hiding');
                toastHideAnimationTimer = null;
            }, 350);
        } else {
            toast.classList.remove('is-hiding');
            toast.style.display = 'none';
        }
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            hideToast(true);
        });
    }

    function updateAdvance(amount, source) {
        // Clamp amount
        amount = Math.max(minAdvance, Math.min(maxAdvance, Math.round(amount)));

        // Update slider if not source
        if (slider && source !== 'slider') {
            slider.value = amount;
        }

        // Update num input if not source
        if (numInput && source !== 'input') {
            numInput.value = amount;
        }

        // Update breakdowns
        if (payNowVal) {
            payNowVal.textContent = formatRupee(amount);
        }

        const remaining = Math.max(0, grandTotal - amount);
        if (remainingVal) {
            remainingVal.textContent = formatRupee(remaining);
        }

        if (btnAmountDisplay) {
            btnAmountDisplay.textContent = formatRupee(amount);
        }

        if (toastAmountDisplay) {
            toastAmountDisplay.textContent = formatRupee(amount);
        }

        // Active state for preset buttons
        presetBtns.forEach(function (btn) {
            const pct = parseInt(btn.dataset.percent, 10);
            let expectedAmount;
            if (pct === 30) {
                expectedAmount = minAdvance;
            } else if (pct === 100) {
                expectedAmount = maxAdvance;
            } else {
                expectedAmount = Math.round(grandTotal * (pct / 100));
            }

            if (amount === expectedAmount) {
                btn.classList.add('is-active');
            } else {
                btn.classList.remove('is-active');
            }
        });
    }

    // Slider input event
    if (slider) {
        slider.addEventListener('input', function () {
            updateAdvance(parseInt(slider.value, 10) || minAdvance, 'slider');
        });
    }

    // Numeric input events
    if (numInput) {
        numInput.addEventListener('input', function () {
            const val = parseInt(numInput.value, 10);
            if (!isNaN(val)) {
                // If within bounds, update all displays smoothly
                if (val >= minAdvance && val <= maxAdvance) {
                    updateAdvance(val, 'input');
                } else {
                    // Update displays without overwriting input text while user types
                    if (payNowVal) payNowVal.textContent = formatRupee(val);
                    if (remainingVal) remainingVal.textContent = formatRupee(Math.max(0, grandTotal - val));
                    if (btnAmountDisplay) btnAmountDisplay.textContent = formatRupee(val);
                    if (toastAmountDisplay) toastAmountDisplay.textContent = formatRupee(val);
                }
            }
        });

        numInput.addEventListener('blur', function () {
            const val = parseInt(numInput.value, 10);
            updateAdvance(isNaN(val) ? minAdvance : val);
        });

        numInput.addEventListener('change', function () {
            const val = parseInt(numInput.value, 10);
            updateAdvance(isNaN(val) ? minAdvance : val);
        });
    }

    // Preset button clicks
    presetBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const pct = parseInt(btn.dataset.percent, 10);
            let targetAmount;
            if (pct === 30) {
                targetAmount = minAdvance;
            } else if (pct === 100) {
                targetAmount = maxAdvance;
            } else {
                targetAmount = Math.round(grandTotal * (pct / 100));
            }
            updateAdvance(targetAmount);
        });
    });

    // Declaration checkbox toggle
    function updateSubmitState(isUserAction) {
        if (!confirmPayBtn) return;
        const isChecked = declarationCheckbox ? declarationCheckbox.checked : false;

        if (isChecked) {
            confirmPayBtn.removeAttribute('disabled');
            confirmPayBtn.disabled = false;
            confirmPayBtn.classList.remove('is-disabled');
            if (isUserAction) {
                showToast();
            }
        } else {
            confirmPayBtn.setAttribute('disabled', 'disabled');
            confirmPayBtn.disabled = true;
            confirmPayBtn.classList.add('is-disabled');
            if (isUserAction) {
                hideToast(false);
            }
        }
    }

    if (declarationCheckbox) {
        declarationCheckbox.addEventListener('change', function () {
            updateSubmitState(true);
        });
        // Initial state: do not trigger toast on load
        updateSubmitState(false);
    }

    // Form submit guard
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!declarationCheckbox || !declarationCheckbox.checked) {
                e.preventDefault();
                alert('Please confirm the measurement declaration before continuing.');
                return false;
            }

            // Ensure amount is clamped before submit
            if (numInput) {
                const val = parseInt(numInput.value, 10);
                const clamped = Math.max(minAdvance, Math.min(maxAdvance, isNaN(val) ? minAdvance : val));
                numInput.value = clamped;
            }
        });
    }

});

/* ---------------------------------------------------------
   LUXE SECURE PAYMENT PAGE (payment.php)
   --------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', function () {
    const paymentPage = document.querySelector('[data-payment-page]');
    if (!paymentPage) return;
        const payTabs = paymentPage.querySelectorAll('.luxe-pay-tab');
        const payPanels = paymentPage.querySelectorAll('.luxe-pay-panel');
        const payBtn = paymentPage.querySelector('#pay-submit-btn');
        const stateReady = paymentPage.querySelector('#payment-state-ready');
        const stateProcessing = paymentPage.querySelector('#payment-state-processing');
        const stateSuccess = paymentPage.querySelector('#payment-state-success');
        const stateFailed = paymentPage.querySelector('#payment-state-failed');
        const copyBtn = paymentPage.querySelector('#copy-ref-btn');
        const orderRefText = paymentPage.querySelector('#order-ref-text');
        const simMethodInput = paymentPage.querySelector('#sim-payment-method');
        const simLabelInput = paymentPage.querySelector('#sim-payment-method-label');
        const simSuccessForm = paymentPage.querySelector('#simulate-success-form');
        const simSuccessBtn = paymentPage.querySelector('#simulate-success-btn');

        function updateSimPaymentMethod(method, label) {
            if (simMethodInput) simMethodInput.value = method;
            if (simLabelInput) simLabelInput.value = label;
        }

        // Tab Switching
        payTabs.forEach(tab => {
            tab.addEventListener('click', function () {
                const tabId = this.dataset.tab;
                payTabs.forEach(t => {
                    t.classList.remove('is-active');
                    t.setAttribute('aria-selected', 'false');
                });
                this.classList.add('is-active');
                this.setAttribute('aria-selected', 'true');

                payPanels.forEach(panel => {
                    if (panel.id === 'tab-panel-' + tabId) {
                        panel.classList.add('is-active');
                        panel.style.display = 'block';
                    } else {
                        panel.classList.remove('is-active');
                        panel.style.display = 'none';
                    }
                });

                // Update hidden payment method inputs based on selected tab
                if (tabId === 'upi') {
                    updateSimPaymentMethod('upi', 'UPI / QR Code (Demo Simulation)');
                } else if (tabId === 'card') {
                    updateSimPaymentMethod('card', 'Credit / Debit Card (Demo Simulation)');
                } else if (tabId === 'netbanking') {
                    const selectedBank = paymentPage.querySelector('input[name="bank_option"]:checked');
                    const bankName = selectedBank ? (selectedBank.closest('label')?.querySelector('span')?.textContent.trim() || selectedBank.value.toUpperCase()) : 'Bank';
                    updateSimPaymentMethod('netbanking', 'Net Banking (' + bankName + ')');
                } else if (tabId === 'wallets') {
                    const selectedWallet = paymentPage.querySelector('input[name="wallet_option"]:checked');
                    const walletName = selectedWallet ? (selectedWallet.closest('label')?.querySelector('span')?.textContent.trim() || selectedWallet.value.toUpperCase()) : 'Wallet';
                    updateSimPaymentMethod('wallet', 'Digital Wallet (' + walletName + ')');
                }
            });
        });

        // UPI App click selection
        const upiAppBtns = paymentPage.querySelectorAll('.luxe-upi-app-btn');
        upiAppBtns.forEach(btn => {
            btn.addEventListener('click', function () {
                upiAppBtns.forEach(b => b.style.borderColor = '#ebdccb');
                this.style.borderColor = '#b78b52';
                const appName = this.querySelector('.luxe-app-name')?.textContent.trim() || this.dataset.app || 'App';
                updateSimPaymentMethod('upi', 'UPI (' + appName + ')');
            });
        });

        // Bank / Wallet radio selection styles & label sync
        const radioItems = paymentPage.querySelectorAll('.luxe-bank-item input, .luxe-wallet-item input');
        radioItems.forEach(radio => {
            radio.addEventListener('change', function () {
                const groupName = this.name;
                const siblings = paymentPage.querySelectorAll(`input[name="${groupName}"]`);
                siblings.forEach(s => {
                    const label = s.closest('label');
                    if (label) label.classList.remove('is-selected');
                });
                const curLabel = this.closest('label');
                if (curLabel) curLabel.classList.add('is-selected');

                const optionName = curLabel ? curLabel.querySelector('span')?.textContent.trim() : this.value.toUpperCase();
                if (groupName === 'bank_option') {
                    updateSimPaymentMethod('netbanking', 'Net Banking (' + optionName + ')');
                } else if (groupName === 'wallet_option') {
                    updateSimPaymentMethod('wallet', 'Digital Wallet (' + optionName + ')');
                }
            });
        });

        // Double-click prevention on simulate success form
        if (simSuccessForm && simSuccessBtn) {
            simSuccessForm.addEventListener('submit', function (e) {
                if (simSuccessBtn.disabled || simSuccessBtn.classList.contains('is-submitting')) {
                    e.preventDefault();
                    return false;
                }
                simSuccessBtn.classList.add('is-submitting');
                simSuccessBtn.disabled = true;
                simSuccessBtn.textContent = 'Processing Payment...';
            });
        }

        // Pay button click -> Processing state with double click prevention
        if (payBtn) {
            payBtn.addEventListener('click', function () {
                if (this.classList.contains('is-processing') || this.disabled) {
                    return;
                }

                // Prevent duplicate clicks
                this.classList.add('is-processing');
                this.disabled = true;
                const btnText = this.querySelector('.luxe-btn-text');
                if (btnText) {
                    btnText.textContent = 'Processing Payment...';
                }

                // Show processing view
                if (stateReady && stateProcessing) {
                    stateReady.style.display = 'none';
                    stateReady.classList.remove('is-visible');
                    stateProcessing.style.display = 'block';
                    stateProcessing.classList.add('is-visible');

                    // Demo notice after 2.5s to clarify demo mode and gateway simulation
                    setTimeout(() => {
                        alert('Demo Mode: Payment Gateway Integration Coming Soon.\n\nNo real money was charged. Use the simulation buttons ("Simulate Successful Payment" or "Simulate Failed Payment") to complete the prototype order flow.');
                        // Return to ready state so customer can interact with demo controls
                        stateProcessing.style.display = 'none';
                        stateProcessing.classList.remove('is-visible');
                        stateReady.style.display = 'block';
                        stateReady.classList.add('is-visible');
                        payBtn.classList.remove('is-processing');
                        payBtn.disabled = false;
                        if (btnText) {
                            btnText.textContent = 'Pay ' + (paymentPage.dataset.amount ? ('₹' + Number(paymentPage.dataset.amount).toLocaleString('en-IN')) : '');
                        }
                    }, 2500);
                }
            });
        }

        // Copy Order Reference
        if (copyBtn && orderRefText) {
            copyBtn.addEventListener('click', function () {
                const text = orderRefText.textContent.trim();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(() => {
                        copyBtn.textContent = '✓';
                        setTimeout(() => { copyBtn.textContent = '❐'; }, 2000);
                    }).catch(() => {
                        prompt('Order Reference:', text);
                    });
                } else {
                    prompt('Order Reference:', text);
                }
            });
        }
    });

    /* ---------------------------------------------------------
       LUXE SMART STEPPER (MOBILE SCROLLING & DISCOVERY HINT)
       --------------------------------------------------------- */
    function initLuxeStepper() {
        const steppers = document.querySelectorAll('[data-luxe-stepper]');
        if (!steppers.length) return;

        steppers.forEach(function (stepper) {
            const currentStepNum = parseInt(stepper.getAttribute('data-current-step') || '1', 10);
            const currentStepEl = stepper.querySelector('.luxe-step.is-current') || stepper.querySelector('[aria-current="step"]');

            function getTargetScroll(smooth) {
                if (!currentStepEl) return 0;
                const containerWidth = stepper.clientWidth;
                const scrollWidth = stepper.scrollWidth;
                if (scrollWidth <= containerWidth) return 0;

                const itemWrapper = currentStepEl.closest('.luxe-stepper-item') || currentStepEl;
                const itemLeft = itemWrapper.offsetLeft;
                const itemWidth = itemWrapper.offsetWidth;

                // Center current step comfortably in visible horizontal area
                const target = Math.max(0, Math.min(scrollWidth - containerWidth, itemLeft - (containerWidth / 2) + (itemWidth / 2)));
                return target;
            }

            // Immediately scroll to current step on page load without animation
            const initialScroll = getTargetScroll(false);
            stepper.scrollLeft = initialScroll;

            // Mobile Swipe: Dynamically update displayed step name based on scroll position
            const container = stepper.closest('.luxe-stepper-container');
            const mobileNumEl = container ? container.querySelector('[data-mobile-step-num]') : null;
            const mobileNameEl = container ? container.querySelector('[data-mobile-step-name]') : null;
            const stepItems = stepper.querySelectorAll('.luxe-stepper-item');

            function updateMobileActiveStepOnScroll() {
                if (!mobileNameEl || !stepItems.length) return;
                if (stepper.scrollWidth <= stepper.clientWidth) return;

                const containerRect = stepper.getBoundingClientRect();
                const containerCenterX = containerRect.left + (containerRect.width / 2);

                let closestItem = null;
                let minDistance = Infinity;

                stepItems.forEach(function (item) {
                    const itemRect = item.getBoundingClientRect();
                    const itemCenterX = itemRect.left + (itemRect.width / 2);
                    const distance = Math.abs(itemCenterX - containerCenterX);
                    if (distance < minDistance) {
                        minDistance = distance;
                        closestItem = item;
                    }
                });

                if (closestItem) {
                    const stepNum = closestItem.getAttribute('data-step');
                    const stepLabel = closestItem.getAttribute('data-label');

                    if (mobileNumEl && stepNum && mobileNumEl.textContent !== stepNum) {
                        mobileNumEl.textContent = stepNum;
                    }
                    if (mobileNameEl && stepLabel && mobileNameEl.textContent !== stepLabel) {
                        mobileNameEl.textContent = stepLabel;
                    }
                }
            }

            let scrollTicking = false;
            stepper.addEventListener('scroll', function () {
                if (!scrollTicking) {
                    window.requestAnimationFrame(function () {
                        updateMobileActiveStepOnScroll();
                        scrollTicking = false;
                    });
                    scrollTicking = true;
                }
            }, { passive: true });

            window.addEventListener('resize', function () {
                updateMobileActiveStepOnScroll();
            }, { passive: true });

            // Locked step guard: strictly prevent navigation on locked circles
            stepper.addEventListener('click', function (e) {
                const lockedEl = e.target.closest('.luxe-step.is-locked, .luxe-workspace-step.is-locked');
                if (lockedEl) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });

            // Mobile One-Time Swipe Discovery Hint:
            // Starts on Step 4+ (Step 4, 5, 6, 7...), runs once per browsing session,
            // demonstrates left <-> right finger swiping without navigation or infinite loop.
            if (currentStepNum >= 4) {
                const hintKey = 'shagun_luxe_stepper_swipe_hint_shown';
                const hasShown = sessionStorage.getItem(hintKey);
                const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const isScrollable = stepper.scrollWidth > stepper.clientWidth;

                if (!hasShown && !prefersReducedMotion && isScrollable) {
                    // Mark as shown immediately so rapid interactions or reloads don't loop
                    sessionStorage.setItem(hintKey, '1');

                    // Brief pause after page load before starting subtle demonstration
                    setTimeout(function () {
                        const nudge = 45;
                        const maxScroll = stepper.scrollWidth - stepper.clientWidth;
                        const nudgeRight = Math.min(maxScroll, initialScroll + nudge);

                        // 1. Gentle nudge right (reveals next steps)
                        stepper.scrollTo({ left: nudgeRight, behavior: 'smooth' });

                        // 2. Gentle nudge left (reveals previous steps)
                        setTimeout(function () {
                            const nudgeLeft = Math.max(0, initialScroll - 35);
                            stepper.scrollTo({ left: nudgeLeft, behavior: 'smooth' });

                            // 3. Smoothly return to current step and stop
                            setTimeout(function () {
                                stepper.scrollTo({ left: initialScroll, behavior: 'smooth' });
                            }, 380);
                        }, 380);
                    }, 450);
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLuxeStepper);
    } else {
        initLuxeStepper();
    }

    /* ==========================================================
       NAVBAR USER DROPDOWN (PROTOTYPE AUTH)
    ========================================================== */
    function initNavUserDropdown() {
        const dropdown = document.querySelector('[data-nav-dropdown]');
        if (!dropdown) return;

        const toggleBtn = dropdown.querySelector('#navUserMenuBtn');
        const menu = dropdown.querySelector('#navUserMenu');
        if (!toggleBtn || !menu) return;

        function openMenu() {
            menu.classList.add('is-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
        }

        function closeMenu() {
            menu.classList.remove('is-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }

        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const isOpen = menu.classList.contains('is-open');
            if (isOpen) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        document.addEventListener('click', function (e) {
            if (!dropdown.contains(e.target)) {
                closeMenu();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && menu.classList.contains('is-open')) {
                closeMenu();
                toggleBtn.focus();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNavUserDropdown);
    } else {
        initNavUserDropdown();
    }
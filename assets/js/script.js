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


const waIcon = document.getElementById("waIcon");
const waCard = document.getElementById("waCard");
const waClose = document.getElementById("waClose");

// icon click → open card
waIcon.addEventListener("click", () => {
    waCard.style.display = "block";
});

// close button
waClose.addEventListener("click", () => {
    waCard.style.display = "none";
    localStorage.setItem("waClosed", "true");
});

// auto open after delay
setTimeout(() => {
    if (!localStorage.getItem("waClosed")) {
        waCard.style.display = "block";
    }
}, 60000); // 60 sec (change to 120000 for 2 min)

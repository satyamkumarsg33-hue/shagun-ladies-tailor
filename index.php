<?php include 'includes/header.php'; ?>

<!-- HERO -->
<div class="hero-section">

    <!-- LEFT SIDE -->
    <div class="hero-left">
        <div class="hero-overlay">
            <div class="hero-copy-desktop">
                <p class="tagline">BESPOKE ELEGANCE</p>
                <h1>Exquisite Tailoring for the Modern Woman – Crafted to Perfection.</h1>
                <p>Crafted just for you.</p>

                <a href="contact.php" class="btn">Book a Consultation</a>
            </div>

            <div class="hero-copy-mobile">
                <p class="mobile-hero-brand">Shagun Ladies Tailor</p>
                <h1 class="mobile-hero-title">Custom Tailoring</h1>

                <div class="mobile-hero-tags">
                    <span>Doorstep</span>
                    <span>Pickup and Delivery</span>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div class="hero-right">
        <h2>Our Services</h2>

        <div class="services">

    <div class="service-card">
        <img src="assets/images/icons/needle.png" class="icon">
        <h3>Precision Blouse Stitching</h3>
        <p>Perfect fitting with modern designs</p>
    </div>

    <div class="service-card">
        <img src="assets/images/icons/dress.png" class="icon">
        <h3>Suit Customization</h3>
        <p>Custom stitching as per your style</p>
    </div>

    <div class="service-card">
        <img src="assets/images/icons/scissors.png" class="icon">
        <h3>Expert Alterations</h3>
        <p>Expert fitting and corrections</p>
    </div>

</div>
<div class="featured">
    <h2>Featured Collections</h2>

    <div class="slider-container">
        <button class="slider-btn left" onclick="slideLeft()">❮</button>

        <div class="slider" id="slider">

            <div class="slide-card">
                <img src="assets/images/kurti.jpg">
                <p>Modern Silk Anarkali</p>
            </div>

            <div class="slide-card">
                <img src="assets/images/lehenga.jpg">
                <p>Contemporary Lehenga</p>
            </div>

            <div class="slide-card">
                <img src="assets/images/blouse.jpg">
                <p>Bespoke Silk Blouse</p>
            </div>

            <div class="slide-card">
                <img src="assets/images/kurti.jpg">
                <p>Designer Kurti</p>
            </div>

        </div>

        <button class="slider-btn right" onclick="slideRight()">❯</button>
    </div>
</div>
    </div>

</div>

<section class="mobile-timeline-section">
    <div class="mobile-timeline-header">
        <span></span>
        <h2>Select Your Stitching Timeline</h2>
        <span></span>
    </div>

    <div class="mobile-timeline-grid">
        <a href="standard-stitching.php" class="mobile-timeline-card mobile-timeline-card-standard">
            <div class="mobile-timeline-figure">
                <img src="assets/images/design 1.jpg" alt="Standard stitching design for women and kids">
            </div>

            <div class="mobile-timeline-copy">
                <h3>Standard Stitching</h3>
                <ul>
                    <li>Blouses</li>
                    <li>Kurtis</li>
                    <li>Lehengas</li>
                    <li>Women & Kids Wear</li>
                </ul>
                <p>Ready in 5-7 days</p>
                <span class="mobile-timeline-btn">Book Now</span>
            </div>
        </a>

        <a href="#our-specialties" class="mobile-timeline-card mobile-timeline-card-special">
            <div class="mobile-timeline-figure">
                <img src="assets/images/blouse-work.jpg" alt="Our specialties in blouse, kurti, and festive stitching">
            </div>

            <div class="mobile-timeline-copy">
                <h3>Our Specialties</h3>
                <ul>
                    <li>Work Blouses</li>
                    <li>Kurti Stitching</li>
                    <li>Festive Wear</li>
                    <li>Girls & Ladies Styles</li>
                </ul>
                <p>Styled for every occasion</p>
                <span class="mobile-timeline-btn">Explore</span>
            </div>
        </a>

        <a href="designs.php" class="mobile-timeline-card mobile-timeline-card-luxe">
            <div class="mobile-timeline-figure">
                <img src="assets/images/luxe-1.jpg" alt="Luxe stitching design for women and kids">
            </div>

            <div class="mobile-timeline-copy">
                <h3>Luxe Stitching</h3>
                <ul>
                    <li>Wedding Looks</li>
                    <li>Reception Wear</li>
                    <li>Premium Finish</li>
                    <li>Women & Kids Outfits</li>
                </ul>
                <p>Ready in 8-10 days</p>
                <span class="mobile-timeline-btn">Book Now</span>
            </div>
        </a>
    </div>
</section>

<section class="mobile-only-banners">
    <div class="mobile-banner mobile-service-banner">
        <div class="mobile-service-banner-image">
            <img src="assets/images/lehenga.jpg" alt="At home draping and saree styling service">
        </div>

        <div class="mobile-service-banner-copy">
            <p class="mobile-banner-eyebrow">No Parlour. No Stress.</p>
            <h2>Perfect Draping at Home</h2>
            <div class="mobile-service-points">
                <span>At Your Doorstep</span>
                <span>Expert Styling</span>
                <span>Quick Service</span>
            </div>
            <p class="mobile-service-price">Starts at Rs. 499</p>
        </div>
    </div>

    <div class="mobile-banner mobile-steps-banner">
        <div class="mobile-steps-banner-header">
            <p>How We Work</p>
            <h2>Tailoring Process</h2>
        </div>

        <div class="mobile-steps-grid">
            <div class="mobile-step">
                <strong>1.</strong>
                <span>Book appointment from website.</span>
            </div>
            <div class="mobile-step">
                <strong>2.</strong>
                <span>Home visit for measurements, pickup, and sample check.</span>
            </div>
            <div class="mobile-step">
                <strong>3.</strong>
                <span>Design consultation for your outfit.</span>
            </div>
            <div class="mobile-step">
                <strong>4.</strong>
                <span>Your order is stitched with care.</span>
            </div>
            <div class="mobile-step">
                <strong>5.</strong>
                <span>Quality check before delivery.</span>
            </div>
            <div class="mobile-step">
                <strong>6.</strong>
                <span>Order delivered to your doorstep.</span>
            </div>
        </div>
    </div>
</section>

<section class="standard-stitching-section">
    <div class="standard-stitching-layout">
        <div class="standard-left-column">
            <a href="standard-stitching.php" class="standard-stitching-card">
                <div class="standard-stitching-header">
                    <h2>Standard Stitching</h2>
                    <p>Everyday fits, zero glitch!</p>
                </div>

                <div class="standard-stitching-gallery">
                    <div class="standard-thumb">
                        <img src="assets/images/design 1.jpg" alt="Standard stitching lehenga design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/design 2.jpg" alt="Standard stitching kurta design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/design 3.jpg" alt="Standard stitching suit design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/blouse-work.jpg" alt="Standard stitching blouse work">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/kurti.jpg" alt="Standard stitching kurti style">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/lehenga.jpg" alt="Standard stitching festive wear">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/design 1.jpg" alt="Standard stitching ethnic design">
                    </div>
                </div>

                <div class="standard-stitching-footer">
                    <span>Ready in just 5-7 days!</span>
                    <span>Book Now</span>
                </div>
            </a>

            <section class="specialties-panel" id="our-specialties">
                <div class="specialties-panel-header">
                    <p class="specialties-label">Crafted For You</p>
                    <h2>Our Specialties</h2>
                </div>

                <div class="categories">
                    <div class="card">
                        <img src="assets/images/blouse-work.jpg" alt="Work on blouse stitching">
                        <h3>Work On Blouse</h3>
                    </div>

                    <div class="card">
                        <img src="assets/images/kurti.jpg" alt="Kurti stitching">
                        <h3>Kurti Stitching</h3>
                    </div>

                    <div class="card">
                        <img src="assets/images/lehenga.jpg" alt="Lehenga stitching">
                        <h3>Lehenga Stitching</h3>
                    </div>
                </div>
            </section>

            <a href="designs.php" class="luxe-stitching-card">
                <div class="standard-stitching-header">
                    <h2>Luxe Stitching</h2>
                    <p>Premium luxury tailoring!</p>
                </div>

                <div class="standard-stitching-gallery luxe-gallery">
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-1.jpg" alt="Luxury blouse back design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-2.jpg" alt="Green bridal blouse embroidery">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-3.jpg" alt="Designer maroon blouse work">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-4.jpg" alt="Heavy handwork blouse design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-5.jpg" alt="Premium blouse front neck design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-6.jpg" alt="Detailed sleeve embroidery blouse">
                    </div>
                </div>

                <div class="standard-stitching-footer">
                    <span>Ready in just 8-10 days</span>
                    <span>Book Now</span>
                </div>
            </a>

            <a href="contact.php" class="luxe-stitching-card machine-work-card" id="machine-work">
                <div class="standard-stitching-header">
                    <h2>Machine Work</h2>
                    <p>Detailed designs with a polished finish!</p>
                </div>

                <div class="standard-stitching-gallery luxe-gallery">
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-1.jpg" alt="Machine work blouse back design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-2.jpg" alt="Machine work blouse embroidery">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-3.jpg" alt="Machine work designer blouse">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-4.jpg" alt="Machine work blouse detailing">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-5.jpg" alt="Machine work neckline design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-6.jpg" alt="Machine work sleeve design">
                    </div>
                </div>

                <div class="standard-stitching-footer">
                    <span>Beautiful detailing for every occasion</span>
                    <span>Book Now</span>
                </div>
            </a>

            <a href="contact.php" class="luxe-stitching-card hand-work-card" id="hand-work">
                <div class="standard-stitching-header">
                    <h2>Hand Work</h2>
                    <p>Handcrafted details for a truly elegant finish!</p>
                </div>

                <div class="standard-stitching-gallery luxe-gallery">
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-1.jpg" alt="Hand work blouse back design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-2.jpg" alt="Hand embroidered blouse design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-3.jpg" alt="Hand work designer blouse">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-4.jpg" alt="Hand work blouse detailing">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-5.jpg" alt="Hand work neckline design">
                    </div>
                    <div class="standard-thumb">
                        <img src="assets/images/luxe-6.jpg" alt="Hand work sleeve design">
                    </div>
                </div>

                <div class="standard-stitching-footer">
                    <span>Crafted with care for every occasion</span>
                    <span>Book Now</span>
                </div>
            </a>

            <section class="process-panel">
                <div class="process-panel-header">
                    <p class="specialties-label">How We Work</p>
                    <h2>Tailoring Process</h2>
                </div>

                <div class="process-steps">
                    <div class="process-step">
                        <div class="process-icon">1</div>
                        <h3>Consultation</h3>
                        <p>We understand your outfit need, style, and occasion before starting.</p>
                    </div>

                    <div class="process-step">
                        <div class="process-icon">2</div>
                        <h3>Fabric & Design</h3>
                        <p>Necklines, sleeve details, fit, and finishing are finalized with care.</p>
                    </div>

                    <div class="process-step">
                        <div class="process-icon">3</div>
                        <h3>Measurement</h3>
                        <p>Precise measurements help us create flattering silhouettes and comfort.</p>
                    </div>

                    <div class="process-step">
                        <div class="process-icon">4</div>
                        <h3>Stitch & Finish</h3>
                        <p>We stitch, refine, and check every detail before final handover.</p>
                    </div>
                </div>

                <a href="process.php" class="process-explore-btn">Explore Now</a>
            </section>

            <section class="why-choose-panel">
                <div class="process-panel-header">
                    <p class="specialties-label">Why Clients Return</p>
                    <h2>Why Choose Us</h2>
                </div>

                <div class="why-choose-grid">
                    <div class="why-choose-item">
                        <h3>Perfect Fit Focus</h3>
                        <p>Every blouse, kurti, and festive piece is tailored with comfort and body-fit in mind.</p>
                    </div>

                    <div class="why-choose-item">
                        <h3>Neat Finishing</h3>
                        <p>Clean stitching, elegant detailing, and refined finishing make every outfit feel premium.</p>
                    </div>

                    <div class="why-choose-item">
                        <h3>Trusted Delivery</h3>
                        <p>We work with clear timelines so you can plan for daily wear, events, and celebrations.</p>
                    </div>

                    <div class="why-choose-item">
                        <h3>Personal Attention</h3>
                        <p>Design discussions, measurement care, and responsive service make the process easy.</p>
                    </div>
                </div>

                <button type="button" class="why-choose-toggle" aria-expanded="false">Show more</button>
            </section>

            <section class="faq-panel">
                <div class="process-panel-header">
                    <p class="specialties-label">Quick Answers</p>
                    <h2>FAQ</h2>
                </div>

                <div class="faq-list">
                    <div class="faq-item">
                        <h3>How many days does stitching usually take?</h3>
                        <p>Standard stitching usually takes around 5-7 days, while luxe work may take 8-10 days depending on design details.</p>
                    </div>

                    <div class="faq-item">
                        <h3>Do you take urgent orders?</h3>
                        <p>Yes, urgent orders can be discussed based on design complexity and delivery date.</p>
                    </div>

                    <div class="faq-item">
                        <h3>Do you take orders for both machine work and hand work?</h3>
                        <p>Yes, we take orders for both machine work and hand work. Machine work is ideal for neat, detailed designs with a quicker finish, while hand work is best for intricate embroidery and premium festive or bridal looks. We can help you choose the right option based on your design, budget, and occasion.</p>
                    </div>

                    <div class="faq-item">
                        <h3>Can I customize neck, sleeve, and back designs?</h3>
                        <p>Yes, we tailor your blouse or outfit according to your preferred neckline, sleeve style, and fitting choice.</p>
                    </div>

                    <div class="faq-item">
                        <h3>Do you also do alterations?</h3>
                        <p>Yes, alteration services are available for better fitting, corrections, and finishing updates.</p>
                    </div>
                </div>

                <button type="button" class="faq-toggle" aria-expanded="false">Show more</button>
            </section>
        </div>

        <aside class="right-side-stack">
            <div class="reviews-card">
                <div class="reviews-card-header">
                    <p class="reviews-label">Customer Love</p>
                    <h3>Google Reviews</h3>
                    <div class="reviews-stars" aria-label="Google review section">★★★★★</div>
                </div>

                <div class="review-item">
                    <p class="review-text">"I had an emergency and urgently needed blouse and fallpico done on my saree for college... Aunty was very understanding and helpful... Price was also very reasonable."</p>
                    <button type="button" class="review-read-more" aria-expanded="false">Read more</button>
                    <span class="review-source">Nidhi Kulkarni • Google Review</span>
                </div>

                <div class="review-item">
                    <p class="review-text">"I found Shagun Ladies Tailoring through Google while searching for a place to stitch my blouse and I'm really glad I chose them... the owner was very..."</p>
                    <button type="button" class="review-read-more" aria-expanded="false">Read more</button>
                    <span class="review-source">Gopika Subash • Google Review</span>
                </div>

                <a href="https://maps.app.goo.gl/BvWMT8yppH7AHw2h8" class="reviews-cta" target="_blank" rel="noopener noreferrer">Read Google Reviews</a>
            </div>

            <section class="blog-panel">
                <div class="blog-panel-header">
                    <p class="specialties-label">Latest Stories</p>
                    <h2>Tailoring Blog</h2>
                </div>

                <div class="blog-list">
                    <article class="blog-card">
                        <img src="assets/images/blouse-work.jpg" alt="Blouse design blog preview">
                        <div class="blog-card-content">
                            <h3>5 Essential Blouse Neckline Designs for 2026</h3>
                            <p>Dummy post for now. Later we can automatically show the latest blog here.</p>
                            <a href="designs.php">Read More</a>
                        </div>
                    </article>

                    <article class="blog-card">
                        <img src="assets/images/luxe-2.jpg" alt="Saree draping and blouse styling blog preview">
                        <div class="blog-card-content">
                            <h3>The Art of Saree Draping With Designer Blouses</h3>
                            <p>Second dummy post placeholder. We can replace this with the newest article later.</p>
                            <a href="designs.php">Read More</a>
                        </div>
                    </article>
                </div>
            </section>

            <section class="contact-panel">
                <div class="contact-panel-top">
                    <div>
                        <p class="specialties-label">Reach Out</p>
                        <h2>Shagun Ladies Tailor</h2>
                    </div>

                    <div class="contact-meta">
                        <p><strong>Phone:</strong> +91 7019179423</p>
                        <p><strong>Map:</strong> Google Business Listing</p>
                        <p><strong>Address:</strong> Shagun Ladies Tailor, Velankanni Road, Electronic City Phase 1, Bengaluru 560100</p>
                        <p><strong>Online Booking:</strong> 24 by 7</p>
                    </div>
                </div>

                <div class="contact-panel-grid">
                    <form class="contact-form" action="contact.php" method="post">
                        <h3>Contact Us Form</h3>
                        <input type="text" name="name" placeholder="Name">
                        <input type="email" name="email" placeholder="Email">
                        <textarea name="message" placeholder="Message" rows="5"></textarea>
                        <button type="submit" class="contact-submit">Submit Enquiry</button>
                    </form>

                    <div class="map-card">
                        <iframe
                            src="https://maps.google.com/maps?q=Shagun%20Ladies%20Tailor%20Bengaluru&z=15&output=embed"
                            title="Shagun Ladies Tailor location map"
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"></iframe>

                        <div class="hours-card">
                            <h3>Operating Hours</h3>
                            <p><span>Monday - Saturday</span><span>10am - 9:30pm</span></p>
                            <p><span>Sunday</span><span>10am - 9:00pm</span></p>
                            <p><span>Online Booking</span><span>24 by 7</span></p>
                        </div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

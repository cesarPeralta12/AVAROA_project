<div class="page-header">
    <div class="header-wrapper row m-0">
        <form class="form-inline search-full col" action="#" method="get">
            <div class="form-group w-100">
                <div class="Typeahead Typeahead--twitterUsers">
                    <div class="u-posRelative">
                        <input class="demo-input Typeahead-input form-control-plaintext w-100" type="text"
                            placeholder="Search Tivo .." name="q" title="" autofocus>
                        <div class="spinner-border Typeahead-spinner" role="status"><span
                                class="sr-only">Loading...</span>
                        </div><i class="close-search" data-feather="x"></i>
                    </div>
                    <div class="Typeahead-menu"></div>
                </div>
            </div>
        </form>
        <div class="header-logo-wrapper col-auto p-0">
            <div class="toggle-sidebar"><i class="status_toggle middle sidebar-toggle" data-feather="grid"> </i></div>
            <div class="logo-header-main"><a href="{{ route('dashboard') }}"><img class="img-fluid for-light"
                        src="<?php echo '/' . $general_setting['app_logo'] ?? ''; ?>" alt=""><img
                        class="img-fluid for-dark" src="<?php echo '/' . $general_setting['app_logo'] ?? ''; ?>"
                        alt=""></a></div>
        </div>
        <div class="left-header col horizontal-wrapper ps-0">
            <div class="left-menu-header">

            </div>
        </div>
        <div class="nav-right col-6 pull-right right-header p-0">
            <ul class="nav-menus">
                {{-- <li>
                    <div class="right-header ps-0">
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text mobile-search"><i
                                        class="fa fa-search"></i></span></div>
                            <input class="form-control" type="text" placeholder="Search Here........">
                        </div>
                    </div>
                </li> --}}
                <li class="serchinput">
                    <div class="serchbox"><i data-feather="search"></i></div>
                    <div class="form-group search-form">
                        <input type="text" placeholder="Search here...">
                    </div>
                </li>
                <li>
                    <div class="mode"><i class="fa fa-moon-o"></i></div>
                </li>
                <li class="onhover-dropdown">
                    <div class="notification-box">
                        <i data-feather="bell"></i>
                        <span id="notification-count" class="badge"></span> <!-- Badge for count -->
                    </div>
                    <ul class="notification-dropdown onhover-show-div" id="notification-list">
                        <li><i data-feather="bell"></i>
                            <h6 class="f-18 mb-0">Notifications</h6>
                        </li>
                        <!-- Notifications will be loaded here dynamically -->
                    </ul>
                </li>


               


                <!--<li class="maximize"><a href="#!" onclick="javascript:toggleFullScreen()"><i-->
                <!--            data-feather="maximize-2"></i></a></li>-->

                <li class="profile-nav onhover-dropdown">
                    <div class="account-user"><i data-feather="user"></i></div>
                    <ul class="profile-dropdown onhover-show-div">
                        <li>
                            <a href="{{ route('edit_profile') }}">
                                <i data-feather="user"></i><span>Mi perfil</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('change_password') }}">
                                <i data-feather="lock"></i><span>Cambiar contraseña</span>
                            </a>
                        </li>
                        <li><a href="{{ route('logout') }}"><i data-feather="log-out"></i><span>Cerrar sesión</span></a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
        <script class="result-template" type="text/x-handlebars-template">
          <div class="ProfileCard u-cf">
          <div class="ProfileCard-avatar"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-airplay m-0"><path d="M5 17H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-1"></path><polygon points="12 15 17 21 7 21 12 15"></polygon></svg></div>
          <div class="ProfileCard-details">
          <div class="ProfileCard-realName">{{$user_session->name}}</div>
          </div>
          </div>
        </script>
        <script class="empty-template"
            type="text/x-handlebars-template"><div class="EmptyMessage">Your search turned up 0 results. This most likely means the backend is down, yikes!</div></script>
    </div>
</div>
<script>

    $(document).ready(function () {
        // Apply mode on page load based on server value
        $.ajax({
            url: "/get-user-mode", // Replace with your actual endpoint to get the user mode
            type: "GET",
            success: function (response) {
                if (response.mode === "dark") {
                    $("body").addClass("dark-only");
                } else {
                    $("body").removeClass("dark-only");
                }
            }
        });

        // Toggle mode and update in the database
        $(".mode").on("click", function () {
            let mode = $("body").hasClass("dark-only") ? "light" : "dark";

            $("body").toggleClass("dark-only");

            // Update the mode in the database
            $.ajax({
                url: "/update-mode",
                type: "POST",
                data: {
                    mode: mode,
                    _token: $('meta[name="csrf-token"]').attr(
                        "content"), // Include CSRF token for security
                },
                success: function (response) {
                    console.log("Mode updated successfully");
                },
                error: function (xhr, status, error) {
                    console.error("Failed to update mode:", error);
                }
            });
        });
        // Fetch notifications when the page loads
        $.ajax({
            url: '{{ route('get.notifications') }}',
            method: 'GET',
            success: function (response) {
                let notifications = response.notifications;
                let notificationList = $('#notification-list');
                let notificationCount = $('#notification-count');

                // Clear existing notifications
                notificationList.empty();

                // Add notifications to the dropdown
                if (notifications.length > 0) {
                    notifications.forEach(function (notification) {
                        let notificationItem = `
                        <li>
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0"><i data-feather="bell"></i></div>
                                <div class="flex-grow-1">
                                    <p><a href="${notification.target_url}">${notification.text}</a><span class="pull-right">${formatTime(notification.created_at)}</span></p>
                                </div>
                            </div>
                        </li>
                    `;
                        notificationList.append(notificationItem);
                    });

                    // Update the notification count
                    notificationCount.text(notifications.length);
                    notificationCount.show();  // Show badge if there are notifications
                } else {
                    notificationList.append('<li>No new notifications</li>');
                    notificationCount.hide();  // Hide badge if no notifications
                }
            },
            error: function (xhr, status, error) {
                console.error("Error fetching notifications:", error);
            }
        });

        // Fetch messages when the page loads
        $.ajax({
            url: '{{ route('get.messages') }}',  // Update the route to your messages route
            method: 'GET',
            success: function (response) {
                let messages = response.messages;
                let messageList = $('#message-list');  // Ensure this element exists in your HTML
                let messageCount = $('#message-count');  // This is your message count

                // Clear existing messages
                messageList.empty();

                // Add messages to the list
                if (messages.length > 0) {
                    messages.forEach(function (message) {
                        // Format the message time
                        let messageTime = new Date(message.created_at).toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit'
                        });

                        // Profile photo URL: Check if the sender has a profile photo
                        let profilePhotoUrl = message.profile_photo
                            ? '{{ asset('profile_photo') }}/' + message.profile_photo
                            : '{{ asset('149071.png') }}';  // Default profile photo

                        // HTML structure for the message item
                        let messageHtml = `
                    <li>
                        <div class="d-flex align-items-start">
                            <div class="message-img">
                                <img src="${profilePhotoUrl}" alt="${message.sender_name}" style="width: 40px; height: 40px;" class="rounded-circle">
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="mb-0"><a href="#">${message.sender_name}</a></h5>
                                <p>${message.message}</p>
                            </div>
                            <div class="notification-right">
                                <span class="message-time">${messageTime}</span>
                                <i data-feather="x"></i>
                            </div>
                        </div>
                    </li>
                `;
                        // Append the generated message HTML to the message list
                        messageList.append(messageHtml);
                    });

                    // Update message count if needed
                    messageCount.text(messages.length);
                    messageCount.show();  // Show badge if there are messages
                } else {
                    messageList.append('<li>No new messages</li>');
                    messageCount.hide();  // Hide badge if no messages
                }
            },
            error: function (xhr, status, error) {
                console.error("Error fetching messages:", error);
            }
        });

        // Function to format the time (e.g., "3 hrs ago")
        function formatTime(time) {
            let date = new Date(time);
            let diff = Math.floor((new Date() - date) / (1000 * 60 * 60)); // Difference in hours

            if (diff < 1) {
                return Math.floor((new Date() - date) / (1000 * 60)) + " min ago";
            } else {
                return diff + " hr ago";
            }
        }
    });


</script>

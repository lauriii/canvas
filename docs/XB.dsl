workspace {

    model {
        sb = person "Ambitious Site Builder"
        cc = person "Content Creator"
        drupal = softwareSystem "Drupal + XB" {
            sb -> this "Defines site structure"
            cc -> this "Creates content within structure"
            xb-admin-ui = container "XB admin UI" {
                description "Define design system and how it is available for Content Creators by opting in SDCs, defining field types for SDC props, defining default layout, defining Content Creator’s freedom…"
                sb -> this "Defines data model + XB design system"
            }
            xb-specific-config = container "XB-specific Config" {
                description "Validatable to the bottom, to guarantee no content breaks while codebase & config evolve"
                xb-admin-ui -> this "Creates and manages"
            }
            xb-ui = container "XB UI" {
                description "The dazzling new UX! Enforces guardrails of data model + design system"
                cc -> this "Creates content within guardrails: defines values for SDC props, places components in open slots, maybe overrides default layout"
                xb-specific-config -> this "Steers"
            }
            drupal-config = container "Config" {
                description "All Drupal config — including data model."
                xb-specific-config -> this "Are additional config entities + third-party settings on existing config"
            }
            drupal-ui = container "Drupal site" {
                description "Drupal as we know it"
                xb-ui -> this "Overrides the add/edit UX for content entities configured to use XB"
                this -> drupal-config "Uses"
            }
            container "Database" {
                description "Content entities etc."
                drupal-ui -> this "Reads from and writes to"
                xb-ui     -> this "Reads from and writes to"
            }
        }
    }

    views {
        systemContext drupal {
            include *
            autolayout lr
        }

        container drupal {
            include *
            autolayout lr
        }

        theme default
    }

}

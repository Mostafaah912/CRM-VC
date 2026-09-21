import { Link } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    FolderGit2,
    GitMerge,
    History,
    LayoutGrid,
    ScrollText,
    ShoppingCart,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as customersIndex } from '@/routes/customers';
import { index as ordersIndex } from '@/routes/orders';
import audit from '@/routes/audit';
import { health, identityConflicts, syncLogs } from '@/routes/system';
import type { NavItem } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const can = useCan();

    const mainNavItems: NavItem[] = [
        {
            title: 'داشبورد',
            href: dashboard(),
            icon: LayoutGrid,
        },
        ...(can('customers', 'view')
            ? [
                  {
                      title: 'مشتریان',
                      href: customersIndex(),
                      icon: Users,
                  },
              ]
            : []),
        ...(can('orders', 'view')
            ? [
                  {
                      title: 'سفارش‌ها',
                      href: ordersIndex(),
                      icon: ShoppingCart,
                  },
              ]
            : []),
        ...(can('audit', 'view')
            ? [
                  {
                      title: 'گزارش رخدادها',
                      href: audit.index(),
                      icon: ScrollText,
                  },
              ]
            : []),
        ...(can('system', 'view')
            ? [
                  {
                      title: 'سلامت سیستم',
                      href: health(),
                      icon: Activity,
                  },
                  {
                      title: 'گزارش همگام‌سازی',
                      href: syncLogs(),
                      icon: History,
                  },
              ]
            : []),
        ...(can('identity', 'review')
            ? [
                  {
                      title: 'تعارض‌های هویت',
                      href: identityConflicts(),
                      icon: GitMerge,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

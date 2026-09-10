import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { Shop, User } from '@/types';

export function UserInfo({
    user,
    showEmail = false,
    shop = null,
}: {
    user: User;
    showEmail?: boolean;
    shop?: Shop | null;
}) {
    const getInitials = useInitials();
    const showAvatar = Boolean(user.avatar && user.avatar !== '');

    return (
        <>
            <Avatar className="h-8 w-8 overflow-hidden rounded-lg">
                {showAvatar ? (
                    <AvatarImage src={user.avatar} alt={user.name} />
                ) : null}
                <AvatarFallback className="rounded-lg text-black dark:text-white">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{user.name}</span>
                {shop ? (
                    <span className="text-muted-foreground truncate text-xs">
                        {shop.name}
                    </span>
                ) : null}
                {!shop && showEmail ? (
                    <span className="text-muted-foreground truncate text-xs">
                        {user.email}
                    </span>
                ) : null}
            </div>
        </>
    );
}

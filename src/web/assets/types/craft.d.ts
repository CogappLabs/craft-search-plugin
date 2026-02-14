/**
 * Type declarations for Craft CMS CP global objects.
 */

interface CraftActionResponse<T = Record<string, unknown>> {
  data: T;
}

interface AdminTableConfig {
  tableSelector: string;
  sortable?: boolean;
  deleteAction?: string;
  reorderAction?: string;
  confirmDeleteMessage?: string;
  onDeleteItem?: (id: string) => void;
  onReorderItems?: (ids: string[]) => void;
}

interface CpModalConfig {
  action?: string;
  params?: Record<string, unknown>;
  closeOnSubmit?: boolean;
}

interface CraftStatic {
  sendActionRequest<T = Record<string, unknown>>(
    method: string,
    action: string,
    options?: {
      data?: Record<string, unknown>;
    },
  ): Promise<CraftActionResponse<T>>;
  postActionRequest<T = Record<string, unknown>>(
    action: string,
    data?: Record<string, unknown>,
  ): Promise<CraftActionResponse<T>>;
  escapeHtml(str: string): string;
  getActionUrl(action: string, params?: Record<string, unknown>): string;
  getCpUrl(path?: string, params?: Record<string, unknown>): string;
  cp: {
    displayNotice(message: string): void;
    displayError(message: string): void;
    displaySuccess(message: string): void;
  };
  AdminTable: new (config: AdminTableConfig) => unknown;
  CpModal: new (action: string, config?: CpModalConfig) => unknown;
}

declare const Craft: CraftStatic;
